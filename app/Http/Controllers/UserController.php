<?php

namespace App\Http\Controllers;

use App\Actions\UsageReport;
use App\Models\AgentConnection;
use App\Models\GitHubInstallation;
use App\Models\Group;
use App\Models\Impersonation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\SocialAccount;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List every user (admins only).
     */
    public function index(): Response
    {
        return Inertia::render('users/index', [
            'users' => User::query()
                ->withCount('groups')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'groups_count' => $user->groups_count,
                    'created_at' => $user->created_at?->toDateString(),
                ]),
        ]);
    }

    /**
     * Everything the install knows about one user (admins only, USR-002). Secrets (passwords, AI keys, tokens) are never sent.
     */
    public function show(Request $request, User $user, UsageReport $usage): Response
    {
        $user->load([
            'organizations',
            'groups.organization',
            'projects' => fn ($query) => $query->with(['organization', 'sandbox'])->withCount(['allMessages', 'tasks'])->latest('updated_at'),
            'skills.organization',
            'socialAccounts',
            'sshKeys',
            'agentConnections',
            'githubAuthorization',
            'githubInstallations',
        ]);

        $impersonations = Impersonation::query()->with('admin')->where('user_id', $user->id)->latest('started_at')->limit(20)->get();
        $invitedWith = Invitation::query()->with(['inviter', 'organization'])->where('accepted_by', $user->id)->latest('accepted_at')->first();
        $invitationsSent = Invitation::query()->with(['acceptedBy', 'organization'])->where('invited_by', $user->id)->latest('id')->limit(50)->get();

        return Inertia::render('users/show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'is_admin' => $user->is_admin,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'has_password' => $user->hasPassword(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'passkeys_count' => $user->passkeys()->count(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_method' => $user->last_login_method,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'organizations' => $user->organizations->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'role' => $organization->pivot->role,
                'is_current' => $organization->id === $user->current_organization_id,
                'joined_at' => $organization->pivot->created_at?->toIso8601String(),
            ]),
            'groups' => $user->groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'organization' => $group->organization->name,
                'role' => $group->pivot->role,
            ]),
            'projects' => $user->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'organization' => $project->organization->name,
                'status' => $project->status->value,
                'agent' => $project->agent_harness?->value,
                'model' => $project->agent_model,
                'messages_count' => $project->all_messages_count,
                'tasks_count' => $project->tasks_count,
                'sandbox' => $project->sandbox ? [
                    'provider' => $project->sandbox->provider,
                    'status' => $project->sandbox->status->value,
                    'error' => $project->sandbox->error,
                ] : null,
                'published_url' => $project->published_url,
                'publish_error' => $project->publish_error,
                'git_remote_url' => $project->git_remote_url,
                'git_sync_error' => $project->git_sync_error,
                'archived' => $project->archived_at !== null,
                'can_open' => $request->user()->can('view', $project),
                'created_at' => $project->created_at?->toIso8601String(),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ]),
            'skills' => $user->skills->map(fn (Skill $skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
                'organization' => $skill->organization->name,
                'shared' => $skill->shared,
            ]),
            'signInMethods' => $user->socialAccounts->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'provider' => $account->provider->label(),
                'email' => $account->email,
                'created_at' => $account->created_at?->toIso8601String(),
            ]),
            'sshKeys' => $user->sshKeys->map(fn (SshKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
            'aiConnections' => $user->agentConnections->map(fn (AgentConnection $connection): array => [
                'id' => $connection->id,
                'provider' => $connection->provider->label(),
                'type' => $connection->credential_type->value,
                'hint' => $connection->hint,
                'is_default' => $connection->is_default,
                'verified_at' => $connection->verified_at?->toIso8601String(),
            ]),
            'github' => [
                'login' => $user->githubAuthorization?->github_login,
                'installations' => $user->githubInstallations->map(fn (GitHubInstallation $installation): array => [
                    'id' => $installation->id,
                    'account' => $installation->account_login,
                    'type' => $installation->account_type,
                ]),
            ],
            'invitedBy' => $invitedWith ? [
                'name' => $invitedWith->inviter?->name,
                'email' => $invitedWith->inviter?->email,
                'organization' => $invitedWith->organization->name,
                'accepted_at' => $invitedWith->accepted_at?->toIso8601String(),
            ] : null,
            'invitationsSent' => $invitationsSent->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'organization' => $invitation->organization->name,
                'status' => $invitation->status(),
                'accepted_by' => $invitation->acceptedBy?->name,
                'created_at' => $invitation->created_at?->toIso8601String(),
            ]),
            'impersonations' => $impersonations->map(fn (Impersonation $impersonation): array => [
                'id' => $impersonation->id,
                'admin' => $impersonation->admin?->name,
                'ip_address' => $impersonation->ip_address,
                'started_at' => $impersonation->started_at->toIso8601String(),
                'ended_at' => $impersonation->ended_at?->toIso8601String(),
            ]),
            'lifetimeUsage' => $usage->lifetime($user),
            'usage' => $usage->for($user, '30d'),
            'sessions' => $this->sessions($user),
        ]);
    }

    /**
     * The user's signed-in browsers, newest first, when sessions are kept in the database.
     *
     * @return list<array{ip_address: string|null, user_agent: string|null, last_active_at: string}>
     */
    private function sessions(User $user): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return array_values(DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'user_agent' => is_string($session->user_agent) ? $session->user_agent : null,
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Sign the user out of every browser (USR-002): their sessions end and remember-me cookies stop working.
     */
    public function signOut(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 403, __('Sign yourself out from Settings → Security.'));

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was signed out everywhere.', ['name' => $user->name])]);

        return to_route('users.show', $user);
    }

    /**
     * Turn off the user's two-factor sign-in, for someone who lost their device (USR-002). They can set it up again.
     */
    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 403, __('Change your own two-factor sign-in from Settings → Security.'));

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor sign-in for :name was turned off.', ['name' => $user->name])]);

        return to_route('users.show', $user);
    }

    /**
     * Grant or revoke admin access (admins only, never on themselves).
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 403, __('You cannot change your own admin access.'));

        $user->is_admin = $request->validate(['is_admin' => ['required', 'boolean']])['is_admin'];
        $user->save();

        return to_route('users.index');
    }
}
