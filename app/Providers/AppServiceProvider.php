<?php

namespace App\Providers;

use App\Enums\SocialProvider;
use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\BlaxelSandboxProvider;
use App\Sandbox\Providers\DeviceSandboxProvider;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\TailscalePublisher;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SystemConfig;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgentRunner::class, fn ($app) => $app->make(config('sandbox.agent')));

        $this->app->bind(Gateway::class, fn () => new Gateway(config('sandbox.gateway_domain'), config('sandbox.gateway_secret')));

        // New sandboxes go to the configured provider; each existing one keeps its own (see RoutingSandboxProvider).
        $this->app->singleton(SandboxProvider::class, function () {
            $providers = [
                'docker' => fn () => new DockerSandboxProvider(config('sandbox.providers.docker')),
                'blaxel' => fn () => new BlaxelSandboxProvider(config('sandbox.providers.blaxel')),
                'runtime' => fn () => new RuntimeSandboxProvider(config('sandbox.providers.runtime')),
                // Only for projects moved to a computer (DESK-010); never an install's provider for new projects.
                'device' => fn () => new DeviceSandboxProvider([
                    'image' => config('sandbox.providers.device.image'),
                    'relay_url' => config('sandbox.providers.device.relay_url'),
                    'timeout' => config('sandbox.providers.device.timeout'),
                    'gateway_domain' => config('sandbox.gateway_domain'),
                    'gateway_secret' => config('sandbox.gateway_secret'),
                ]),
            ];
            $provider = config('sandbox.provider');

            return match (true) {
                $provider === 'fake' => new FakeSandboxProvider,
                isset($providers[$provider]) => new RoutingSandboxProvider($providers, $provider),
                default => throw new InvalidArgumentException("Sandbox provider [{$provider}] isn't implemented yet. Use \"docker\", \"blaxel\", \"runtime\" or add a provider in app/Sandbox/Providers."),
            };
        });

        $this->app->singleton(Publisher::class, fn () => match ($publisher = config('sandbox.publisher')) {
            'tailscale' => new TailscalePublisher(config('sandbox.tailscale'), config('sandbox.proxy_port')),
            'fake' => new FakePublisher,
            default => throw new InvalidArgumentException("Publisher [{$publisher}] doesn't exist. Use \"tailscale\"."),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Settings admins saved in the app win over `.env` (ADMIN-001, ADMIN-002).
        SystemSetting::flush();
        SystemConfig::apply();
        Queue::before(fn () => SystemConfig::refresh());

        // `/o/{organization}` addresses (ORG-002). Bound explicitly: controllers take it from ResolveOrganization.
        Route::model('organization', Organization::class);

        Gate::define('manage-users', fn (User $user): bool => $user->is_admin);
        Gate::define('administer', fn (User $user): bool => $user->is_admin);

        // Each chat checks its Claude sign-in every few seconds while signed out (AI-005); a bucket per chat, so several open
        // chats don't use each other's up (or the user's shared one) and lose the "Sign in to Claude" button.
        RateLimiter::for('claude-login', fn (Request $request): Limit => Limit::perMinute(60)->by(
            $request->user()?->id.'|'.$request->route()?->originalParameter('project').'|'.$request->input('task'),
        ));

        // When and how each person last signed in, for admins (USR-002). Remember-me cookies picking a session back up aren't a sign-in.
        Event::listen(function (Login $event): void {
            $request = request();
            $method = match ($request->route()?->getName()) {
                'login.store', 'register.store' => 'password',
                'passkey.login' => 'passkey',
                'social.callback' => $request->route('provider') instanceof SocialProvider ? $request->route('provider')->value : null,
                'two-factor.login.store' => $request->session()->pull('login.method', 'password'),
                default => null,
            };

            if ($method !== null && $event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now(), 'last_login_method' => $method])->saveQuietly();
            }
        });

        // Signing out while impersonating ends it too (USR-003).
        Event::listen(function (Logout $event): void {
            if (request()->hasSession() && ($id = request()->session()->pull(Impersonation::SESSION_KEY))) {
                Impersonation::query()->whereKey($id)->first()?->end();
            }
        });

        // Verifying an email at an organization's verified domain joins that organization (ORG-008).
        Event::listen(function (Verified $event): void {
            if ($event->user instanceof User) {
                $event->user->joinOrganizationByEmailDomain();
            }
        });

        Event::listen(fn (SocialiteWasCalled $event) => $event->extendSocialite('microsoft', MicrosoftProvider::class));

        // `composer run dev` runs the scheduler too, so idle Docker sandboxes are suspended locally (SBX-007).
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
