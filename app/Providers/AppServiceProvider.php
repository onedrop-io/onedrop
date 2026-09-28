<?php

namespace App\Providers;

use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\BlaxelSandboxProvider;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\TailscalePublisher;
use App\Sandbox\SandboxProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        $this->app->bind(Gateway::class, fn () => new Gateway(config('sandbox.gateway_domain')));

        $this->app->singleton(SandboxProvider::class, fn () => match ($provider = config('sandbox.provider')) {
            'docker' => new DockerSandboxProvider(config('sandbox.providers.docker')),
            'runtime' => new RuntimeSandboxProvider(config('sandbox.providers.runtime')),
            'blaxel' => new BlaxelSandboxProvider(config('sandbox.providers.blaxel')),
            'fake' => new FakeSandboxProvider,
            default => throw new InvalidArgumentException("Sandbox provider [{$provider}] isn't implemented yet. Use \"docker\", \"blaxel\", \"runtime\" or add a provider in app/Sandbox/Providers."),
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

        Gate::define('manage-users', fn (User $user): bool => $user->is_admin);

        Event::listen(fn (SocialiteWasCalled $event) => $event->extendSocialite('microsoft', MicrosoftProvider::class));
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
