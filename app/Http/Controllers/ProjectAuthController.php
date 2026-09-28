<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Group;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\AppAddresses;
use App\Sandbox\DatabaseException;
use App\Sandbox\OneDropSignIn;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectAuthController extends Controller
{
    /**
     * How the app's sign-in is set up, with the addresses its sign-in page and provider callbacks live at.
     */
    public function show(Project $project, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $this->describe($project, $auth->status($sandbox)));
    }

    /**
     * One page of the app's users.
     */
    public function users(Request $request, Project $project, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $auth->users($sandbox, (string) ($validated['search'] ?? ''), (int) ($validated['page'] ?? 1)));
    }

    /**
     * Add a user with a password.
     */
    public function storeUser(Request $request, Project $project, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $auth->createUser($sandbox, $validated['name'], $validated['email'], $validated['password']));
    }

    /**
     * Change a user's name, email or password, turn their account off or on, or require a new password.
     */
    public function updateUser(Request $request, Project $project, string $user, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255'],
            'sign_out' => ['sometimes', 'boolean'],
            'disabled' => ['sometimes', 'boolean'],
            'password_change_required' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'required', 'string', 'max:40'],
        ]);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($auth, $user, $validated) {
            $details = Arr::only($validated, ['name', 'email', 'role'])
                + array_map(boolval(...), Arr::only($validated, ['disabled', 'password_change_required']));

            if ($details !== []) {
                $auth->updateUser($sandbox, $user, $details);
            }

            if (isset($validated['password'])) {
                $auth->setPassword($sandbox, $user, $validated['password'], (bool) ($validated['sign_out'] ?? false));
            }

            return ['updated' => true];
        });
    }

    public function destroyUser(Project $project, string $user, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($auth, $user) {
            $auth->deleteUser($sandbox, $user);

            return ['deleted' => true];
        });
    }

    /**
     * Download every user as a CSV file.
     */
    public function export(Project $project, WorkspaceAuth $auth): JsonResponse|StreamedResponse
    {
        Gate::authorize('view', $project);

        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            $users = $auth->export($sandbox)['users'];
        } catch (DatabaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'name', 'email', 'role', 'status', 'joined', 'last_signed_in'], escape: '');

            foreach ($users as $user) {
                fputcsv($out, array_map(self::csvCell(...), [
                    $user['id'],
                    $user['name'],
                    $user['email'],
                    $user['role'],
                    $user['disabled_at'] !== null ? 'turned off' : ($user['password_change_required'] ? 'must choose new password' : 'active'),
                    $user['created_at'],
                    $user['last_login_at'],
                ]), escape: '');
            }

            fclose($out);
        }, Str::slug($project->name).'-users.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Text safe to open in a spreadsheet: values starting with =, +, - or @ would run as formulas.
     */
    protected static function csvCell(mixed $value): string
    {
        $text = is_array($value) ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    /**
     * A one-time link that opens the app signed in as this user.
     */
    public function signInAs(Project $project, string $user, WorkspaceAuth $auth, AppAddresses $addresses): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'url' => $addresses->previewLink($project, $auth->signInLink($sandbox, $user))
                ?? throw new SandboxException(__("The app's preview address isn't known yet.")),
        ]);
    }

    /**
     * Who may use Sign in with OneDrop: everyone (null) or members of these groups.
     */
    public function oneDropAccess(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'group_ids' => ['present', 'nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:groups,id'],
        ]);

        $project->update(['onedrop_group_ids' => $validated['group_ids'] === null ? null : array_values(array_unique(array_map(intval(...), $validated['group_ids'])))]);

        return response()->json($this->oneDrop($project));
    }

    /**
     * End all of a user's sessions.
     */
    public function signOutUser(Project $project, string $user, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($auth, $user) {
            $auth->signOut($sandbox, $user);

            return ['signed_out' => true];
        });
    }

    /**
     * Ask the agent to add the account controls the app doesn't have yet.
     */
    public function helper(Project $project, WorkspaceAuth $auth, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $auth, $queue) {
            $message = WorkspaceAuth::upgradeRequest($auth->capabilities($sandbox))
                ?? throw new DatabaseException(__('Your app already has every account control.'));

            return ['queued' => (bool) $queue->send($project, $message)->queued];
        });
    }

    /**
     * Save a sign-in provider's keys into the app's env file.
     */
    public function keys(Request $request, Project $project, WorkspaceAuth $auth): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'provider' => ['required', Rule::in(WorkspaceAuth::PROVIDERS)],
            'client_id' => ['nullable', 'string', 'max:500', 'required_without:client_secret'],
            'client_secret' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $this->describe(
            $project,
            $auth->saveKeys($sandbox, $validated['provider'], $validated['client_id'] ?? null, $validated['client_secret'] ?? null),
        ));
    }

    /**
     * Ask the agent to add sign-in with the chosen methods, or change the app to use exactly these.
     */
    public function setup(Request $request, Project $project, WorkspaceAuth $auth, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $methods = $request->validate([
            'methods' => ['required', 'array', 'min:1'],
            'methods.*' => ['string', Rule::in(array_keys(WorkspaceAuth::METHODS))],
        ])['methods'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $auth, $queue, $methods) {
            $status = $auth->status($sandbox);
            $current = $status['configured'] ? $status['methods'] : null;

            if ($current !== null && array_diff($methods, $current) === [] && array_diff($current, $methods) === []) {
                throw new DatabaseException(__('Those are already the sign-in methods your app has.'));
            }

            $this->syncOneDrop($project, $sandbox, $auth, $status, in_array('onedrop', $methods, true));

            $message = $queue->send($project, WorkspaceAuth::request($methods, $current));

            return ['queued' => (bool) $message->queued];
        });
    }

    /**
     * Turn the app's OneDrop client on (with fresh keys in its env file) or off, to match the chosen methods.
     *
     * @param  array<string, mixed>  $status
     */
    protected function syncOneDrop(Project $project, Sandbox $sandbox, WorkspaceAuth $auth, array $status, bool $wanted): void
    {
        if (! $wanted) {
            if ($project->onedrop_enabled) {
                app(OneDropSignIn::class)->disable($project);
            }

            return;
        }

        $callbackPath = str_replace('{provider}', 'onedrop', $status['callback_path'] ?? '/auth/{provider}/callback');
        $auth->saveOneDrop($sandbox, app(OneDropSignIn::class)->enable($project, $callbackPath));
    }

    /**
     * @return array{enabled: bool, group_ids: list<int>|null, groups: list<array{id: int, name: string}>}
     */
    protected function oneDrop(Project $project): array
    {
        return [
            'enabled' => (bool) $project->onedrop_enabled,
            'group_ids' => $project->onedrop_group_ids,
            'groups' => Group::orderBy('name')->get(['id', 'name'])->map(fn (Group $group) => $group->only('id', 'name'))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function describe(Project $project, array $status): array
    {
        if (! $status['configured']) {
            return $status;
        }

        $addresses = app(AppAddresses::class);
        $callback = fn (string $provider) => $addresses->everywhere($project, str_replace('{provider}', $provider, $status['callback_path']));

        $providers = array_map(fn (string $provider) => [
            'id' => $provider,
            'enabled' => in_array($provider, $status['methods'], true),
            'client_id_set' => $status['keys'][$provider]['client_id'] ?? false,
            'client_secret_set' => $status['keys'][$provider]['client_secret'] ?? false,
            'callback_urls' => $callback($provider),
        ], WorkspaceAuth::PROVIDERS);

        return [
            'configured' => true,
            'library' => $status['library'],
            'methods' => $status['methods'],
            'roles' => $status['roles'] ?? [],
            'env_file' => $status['env_file'],
            'users_table' => $status['users_table'],
            'providers' => $providers,
            'onedrop' => $this->oneDrop($project),
            'login_url' => $addresses->previewLink($project, $status['login_path']),
            'published_login_url' => ($origin = $addresses->publishedOrigin($project)) ? $origin.$status['login_path'] : null,
            'can_create' => $status['can_create'] ?? false,
        ];
    }

    /**
     * Run a call against a running sandbox, turning failures into JSON errors.
     *
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($call($sandbox));
        } catch (DatabaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
