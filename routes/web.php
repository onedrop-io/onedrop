<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\DatabaseBackupController;
use App\Http\Controllers\Admin\SandboxProviderController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerMonitoringController;
use App\Http\Controllers\AgentModelController;
use App\Http\Controllers\ChatGptAuthController;
use App\Http\Controllers\ClaudeLoginController;
use App\Http\Controllers\GitHubAppController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OneDropOAuthController;
use App\Http\Controllers\OpenRouterAuthController;
use App\Http\Controllers\ProjectAgentController;
use App\Http\Controllers\ProjectAttachmentController;
use App\Http\Controllers\ProjectAuthController;
use App\Http\Controllers\ProjectBrowserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDatabaseController;
use App\Http\Controllers\ProjectDeveloperController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectFlagController;
use App\Http\Controllers\ProjectGitController;
use App\Http\Controllers\ProjectGrowthController;
use App\Http\Controllers\ProjectIconController;
use App\Http\Controllers\ProjectLogController;
use App\Http\Controllers\ProjectMessageController;
use App\Http\Controllers\ProjectMonitoringController;
use App\Http\Controllers\ProjectPublicationController;
use App\Http\Controllers\ProjectRequirementsController;
use App\Http\Controllers\ProjectSearchController;
use App\Http\Controllers\ProjectSecretController;
use App\Http\Controllers\ProjectShareController;
use App\Http\Controllers\ProjectSkillController;
use App\Http\Controllers\ProjectStorageController;
use App\Http\Controllers\ProjectTestController;
use App\Http\Controllers\SandboxActivityController;
use App\Http\Controllers\SandboxEventController;
use App\Http\Controllers\SandboxGatewayController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\SshKeyController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMessageController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('pricing', 'pricing')->name('pricing');

// Shared projects' public pages (SHARE-001) and "Remix this" (SHARE-002).
Route::get('s/{share}', [ShareController::class, 'show'])->name('shares.show');
Route::get('s/{share}/card.png', [ShareController::class, 'card'])->name('shares.card');
Route::get('s/{share}/screenshot.png', [ShareController::class, 'screenshot'])->name('shares.screenshot');
Route::post('s/{share}/remix', [ShareController::class, 'remix'])->middleware('throttle:30,1')->name('shares.remix');

Route::get('invite/{token}', AcceptInvitationController::class)->name('invitations.accept');

// The install's own logo (ADMIN-001); the sign-in page shows it too.
Route::get('branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

// Log in with Google, GitHub, etc. Signed-in users use the same routes to connect a provider.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('login/{provider}', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('login/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});

// Preview/shell addresses on servers. No session here: the app's login cookie never reaches these hosts.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, HandleInertiaRequests::class])->group(function () {
    // Used by Caddy to authorize preview/shell traffic and on-demand certificates.
    Route::get('sandbox-gateway/authorize', [SandboxGatewayController::class, 'authorize'])->name('sandbox-gateway.authorize');
    Route::get('sandbox-gateway/certificate', [SandboxGatewayController::class, 'certificate'])->name('sandbox-gateway.certificate');

    // Served on each preview/shell address: trades the app's hand-off token for that address's cookie.
    Route::get('__onedrop/enter', [SandboxGatewayController::class, 'enter'])->name('sandbox-gateway.enter');
});

// "Sign in with OneDrop" for apps built here: called by each app's server, authenticated by its client secret or access token.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, HandleInertiaRequests::class])
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::post('oauth/token', [OneDropOAuthController::class, 'token'])->name('onedrop.token');
        Route::get('oauth/userinfo', [OneDropOAuthController::class, 'userinfo'])->name('onedrop.userinfo');
    });

// Called by the agent forwarder inside a sandbox; authenticated by a per-run bearer token. Tasks' runs
// report to their own address (TASK-001), and a sandbox may run several at once.
Route::post('sandbox-events/{sandbox}', [SandboxEventController::class, 'store'])
    ->middleware('throttle:3000,1')
    ->name('sandbox-events.store');
Route::post('sandbox-events/{sandbox}/tasks/{task}', [SandboxEventController::class, 'store'])
    ->middleware('throttle:3000,1')
    ->name('sandbox-events.tasks.store');
// Called by the file watcher inside a sandbox (FILE-004); the sandbox gets this signed address when it's created.
Route::post('sandbox-events/{sandbox}/files', [SandboxEventController::class, 'filesChanged'])
    ->middleware(['signed:relative', 'throttle:600,1'])
    ->name('sandbox-events.files');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('oauth/authorize', [OneDropOAuthController::class, 'authorize'])->middleware('throttle:60,1')->name('onedrop.authorize');
    Route::get('onboarding/ai', [OnboardingController::class, 'ai'])->name('onboarding.ai');
    Route::get('auth/openrouter', [OpenRouterAuthController::class, 'redirect'])->name('openrouter.redirect');
    Route::get('auth/openrouter/callback', [OpenRouterAuthController::class, 'callback'])->name('openrouter.callback');
    Route::post('auth/chatgpt', [ChatGptAuthController::class, 'store'])->middleware('throttle:10,1')->name('chatgpt.store');
    Route::post('auth/chatgpt/poll', [ChatGptAuthController::class, 'poll'])->middleware('throttle:60,1')->name('chatgpt.poll');

    // Previews and shells (the project's people), and privately published apps (anyone signed in, AI set up or not).
    Route::get('projects/{project}/open/{kind}', [SandboxGatewayController::class, 'open'])->name('projects.gateway.open');

    Route::middleware('agent.connected')->group(function () {
        Route::get('dashboard', [ProjectController::class, 'create'])->name('dashboard');

        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/search', ProjectSearchController::class)->name('projects.search');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::post('projects/{project}/name', [ProjectController::class, 'regenerateName'])->name('projects.name.regenerate');
        Route::post('projects/{project}/messages', [ProjectMessageController::class, 'store'])->name('projects.messages.store');
        Route::get('projects/{project}/attachments/{attachment}', [ProjectAttachmentController::class, 'show'])->name('projects.attachments.show');
        Route::post('projects/{project}/sandbox/activity', [SandboxActivityController::class, 'store'])->name('projects.sandbox.activity');
        Route::get('projects/{project}/files', [ProjectFileController::class, 'index'])->name('projects.files.index');
        Route::get('projects/{project}/files/version', [ProjectFileController::class, 'version'])->name('projects.files.version');
        Route::get('projects/{project}/files/show', [ProjectFileController::class, 'show'])->name('projects.files.show');
        Route::put('projects/{project}/files', [ProjectFileController::class, 'update'])->name('projects.files.update');
        Route::post('projects/{project}/files', [ProjectFileController::class, 'store'])->name('projects.files.store');
        Route::post('projects/{project}/files/upload', [ProjectFileController::class, 'upload'])->name('projects.files.upload');
        Route::post('projects/{project}/files/move', [ProjectFileController::class, 'move'])->name('projects.files.move');
        Route::delete('projects/{project}/files', [ProjectFileController::class, 'destroy'])->name('projects.files.destroy');
        Route::get('projects/{project}/files/download', [ProjectFileController::class, 'download'])->name('projects.files.download');
        Route::get('projects/{project}/logs', [ProjectLogController::class, 'index'])->name('projects.logs.index');
        Route::get('projects/{project}/requirements', [ProjectRequirementsController::class, 'show'])->name('projects.requirements.show');
        Route::patch('projects/{project}/requirements', [ProjectRequirementsController::class, 'update'])->name('projects.requirements.update');
        Route::get('projects/{project}/tests', [ProjectTestController::class, 'index'])->name('projects.tests.index');
        Route::post('projects/{project}/tests/run', [ProjectTestController::class, 'store'])->name('projects.tests.run');
        Route::post('projects/{project}/tests/write', [ProjectTestController::class, 'write'])->name('projects.tests.write');
        Route::post('projects/{project}/tests/runner', [ProjectTestController::class, 'openRunner'])->name('projects.tests.runner.open');
        Route::delete('projects/{project}/tests/runner', [ProjectTestController::class, 'closeRunner'])->name('projects.tests.runner.close');
        Route::get('projects/{project}/browser', [ProjectBrowserController::class, 'show'])->name('projects.browser.show');
        Route::post('projects/{project}/browser', [ProjectBrowserController::class, 'store'])->name('projects.browser.store');
        Route::delete('projects/{project}/browser', [ProjectBrowserController::class, 'destroy'])->name('projects.browser.destroy');
        Route::get('projects/{project}/tests/recording', [ProjectTestController::class, 'recording'])->name('projects.tests.recording');
        Route::get('projects/{project}/monitoring', [ProjectMonitoringController::class, 'show'])->name('projects.monitoring.show');
        Route::get('projects/{project}/database/connections', [ProjectDatabaseController::class, 'connections'])->name('projects.database.connections');
        Route::get('projects/{project}/database/tables', [ProjectDatabaseController::class, 'tables'])->name('projects.database.tables');
        Route::get('projects/{project}/database/rows', [ProjectDatabaseController::class, 'rows'])->name('projects.database.rows');
        Route::post('projects/{project}/database/changes', [ProjectDatabaseController::class, 'change'])->name('projects.database.change');
        Route::post('projects/{project}/database/query', [ProjectDatabaseController::class, 'query'])->name('projects.database.query');
        Route::get('projects/{project}/auth', [ProjectAuthController::class, 'show'])->name('projects.auth.show');
        Route::get('projects/{project}/auth/users', [ProjectAuthController::class, 'users'])->name('projects.auth.users');
        Route::get('projects/{project}/auth/users/export', [ProjectAuthController::class, 'export'])->name('projects.auth.users.export');
        Route::post('projects/{project}/auth/users/{user}/sign-in', [ProjectAuthController::class, 'signInAs'])->name('projects.auth.users.sign-in');
        Route::put('projects/{project}/auth/onedrop', [ProjectAuthController::class, 'oneDropAccess'])->name('projects.auth.onedrop');
        Route::post('projects/{project}/auth/users', [ProjectAuthController::class, 'storeUser'])->name('projects.auth.users.store');
        Route::patch('projects/{project}/auth/users/{user}', [ProjectAuthController::class, 'updateUser'])->name('projects.auth.users.update');
        Route::delete('projects/{project}/auth/users/{user}', [ProjectAuthController::class, 'destroyUser'])->name('projects.auth.users.destroy');
        Route::post('projects/{project}/auth/users/{user}/sign-out', [ProjectAuthController::class, 'signOutUser'])->name('projects.auth.users.sign-out');
        Route::post('projects/{project}/auth/helper', [ProjectAuthController::class, 'helper'])->name('projects.auth.helper');
        Route::put('projects/{project}/auth/keys', [ProjectAuthController::class, 'keys'])->name('projects.auth.keys');
        Route::post('projects/{project}/auth/setup', [ProjectAuthController::class, 'setup'])->name('projects.auth.setup');
        Route::get('projects/{project}/growth', [ProjectGrowthController::class, 'show'])->name('projects.growth.show');
        Route::post('projects/{project}/growth/seo-scan', [ProjectGrowthController::class, 'scan'])->name('projects.growth.scan');
        Route::post('projects/{project}/growth/events', [ProjectGrowthController::class, 'addEvents'])->name('projects.growth.events');
        Route::post('projects/{project}/share', [ProjectShareController::class, 'store'])->name('projects.share.store');
        Route::post('projects/{project}/share/card', [ProjectShareController::class, 'refresh'])->name('projects.share.refresh');
        Route::delete('projects/{project}/share', [ProjectShareController::class, 'destroy'])->name('projects.share.destroy');
        Route::get('projects/{project}/icon', [ProjectIconController::class, 'show'])->name('projects.icon.show');
        Route::post('projects/{project}/icon', [ProjectIconController::class, 'update'])->name('projects.icon.update');
        Route::post('projects/{project}/icon/draw', [ProjectIconController::class, 'draw'])->name('projects.icon.draw');
        Route::get('projects/{project}/flags', [ProjectFlagController::class, 'index'])->name('projects.flags.index');
        Route::post('projects/{project}/flags', [ProjectFlagController::class, 'store'])->name('projects.flags.store');
        Route::patch('projects/{project}/flags/{flag}', [ProjectFlagController::class, 'update'])->name('projects.flags.update');
        Route::delete('projects/{project}/flags/{flag}', [ProjectFlagController::class, 'destroy'])->name('projects.flags.destroy');
        Route::get('projects/{project}/skills', [ProjectSkillController::class, 'index'])->name('projects.skills.index');
        Route::post('projects/{project}/skills', [ProjectSkillController::class, 'store'])->name('projects.skills.store');
        Route::post('projects/{project}/skills/import', [ProjectSkillController::class, 'import'])->name('projects.skills.import');
        Route::post('projects/{project}/skills/upload', [ProjectSkillController::class, 'upload'])->name('projects.skills.upload');
        Route::post('projects/{project}/skills/agent', [ProjectSkillController::class, 'create'])->name('projects.skills.create');
        Route::put('projects/{project}/skills/{skill}/enabled', [ProjectSkillController::class, 'toggle'])->name('projects.skills.toggle');
        Route::get('projects/{project}/project-skills', [ProjectSkillController::class, 'projectIndex'])->name('projects.project-skills.index');
        Route::get('projects/{project}/project-skills/show', [ProjectSkillController::class, 'projectShow'])->name('projects.project-skills.show');
        Route::post('projects/{project}/project-skills/save', [ProjectSkillController::class, 'saveProject'])->name('projects.project-skills.save');
        Route::get('skills/{skill}', [SkillController::class, 'show'])->name('skills.show');
        Route::patch('skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
        Route::delete('skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');
        Route::get('projects/{project}/git/github-app/install', [GitHubAppController::class, 'install'])->name('projects.git.github-app.install');
        Route::get('projects/{project}/git/github-app/repositories', [GitHubAppController::class, 'repositories'])->name('projects.git.github-app.repositories');
        Route::get('projects/{project}/git/github-app/branches', [GitHubAppController::class, 'branches'])->name('projects.git.github-app.branches');
        Route::get('projects/{project}/git/github-app/availability', [GitHubAppController::class, 'availability'])->name('projects.git.github-app.availability');
        Route::post('projects/{project}/git/github-app/repositories', [GitHubAppController::class, 'create'])->name('projects.git.github-app.create');
        Route::put('projects/{project}/git/github-app/remote', [GitHubAppController::class, 'connect'])->name('projects.git.github-app.connect');
        Route::get('github/callback', [GitHubAppController::class, 'callback'])->name('github-app.callback');
        Route::get('projects/{project}/git', [ProjectGitController::class, 'index'])->name('projects.git.index');
        Route::get('projects/{project}/git/commits', [ProjectGitController::class, 'log'])->name('projects.git.log');
        Route::get('projects/{project}/git/commits/{sha}', [ProjectGitController::class, 'show'])->where('sha', '[0-9a-f]{7,40}')->name('projects.git.show');
        Route::get('projects/{project}/git/commits/{sha}/diff', [ProjectGitController::class, 'diff'])->where('sha', '[0-9a-f]{7,40}')->name('projects.git.diff');
        Route::get('projects/{project}/git/changes/diff', [ProjectGitController::class, 'changeDiff'])->name('projects.git.change-diff');
        Route::post('projects/{project}/git/commit', [ProjectGitController::class, 'commit'])->name('projects.git.commit');
        Route::post('projects/{project}/git/discard', [ProjectGitController::class, 'discard'])->name('projects.git.discard');
        Route::post('projects/{project}/git/discard-hunk', [ProjectGitController::class, 'discardHunk'])->name('projects.git.discard-hunk');
        Route::post('projects/{project}/git/switch', [ProjectGitController::class, 'switch'])->name('projects.git.switch');
        Route::post('projects/{project}/git/restore', [ProjectGitController::class, 'restore'])->name('projects.git.restore');
        Route::put('projects/{project}/git/remote', [ProjectGitController::class, 'connect'])->name('projects.git.connect');
        Route::delete('projects/{project}/git/remote', [ProjectGitController::class, 'disconnect'])->name('projects.git.disconnect');
        Route::post('projects/{project}/git/remote/github', [ProjectGitController::class, 'github'])->name('projects.git.github');
        Route::post('projects/{project}/git/undo-commit', [ProjectGitController::class, 'undoCommit'])->name('projects.git.undo-commit');
        Route::post('projects/{project}/git/combine/draft', [ProjectGitController::class, 'combineDraft'])->name('projects.git.combine-draft');
        Route::post('projects/{project}/git/combine', [ProjectGitController::class, 'combine'])->name('projects.git.combine');
        Route::post('projects/{project}/git/pull-request', [ProjectGitController::class, 'pullRequest'])->name('projects.git.pull-request');
        Route::post('projects/{project}/git/push', [ProjectGitController::class, 'push'])->name('projects.git.push');
        Route::post('projects/{project}/git/pull', [ProjectGitController::class, 'pull'])->name('projects.git.pull');
        Route::get('projects/{project}/secrets', [ProjectSecretController::class, 'index'])->name('projects.secrets.index');
        Route::get('projects/{project}/secrets/value', [ProjectSecretController::class, 'show'])->name('projects.secrets.show');
        Route::post('projects/{project}/secrets', [ProjectSecretController::class, 'store'])->name('projects.secrets.store');
        Route::put('projects/{project}/secrets', [ProjectSecretController::class, 'update'])->name('projects.secrets.update');
        Route::delete('projects/{project}/secrets', [ProjectSecretController::class, 'destroy'])->name('projects.secrets.destroy');
        Route::get('projects/{project}/storage', [ProjectStorageController::class, 'index'])->name('projects.storage.index');
        Route::post('projects/{project}/storage', [ProjectStorageController::class, 'store'])->name('projects.storage.store');
        Route::delete('projects/{project}/storage/{bucket}', [ProjectStorageController::class, 'destroy'])->name('projects.storage.destroy');
        Route::get('projects/{project}/storage/{bucket}/objects', [ProjectStorageController::class, 'objects'])->name('projects.storage.objects');
        Route::post('projects/{project}/storage/{bucket}/objects', [ProjectStorageController::class, 'upload'])->name('projects.storage.upload');
        Route::delete('projects/{project}/storage/{bucket}/objects', [ProjectStorageController::class, 'destroyObject'])->name('projects.storage.objects.destroy');
        Route::get('projects/{project}/storage/{bucket}/download', [ProjectStorageController::class, 'download'])->name('projects.storage.download');
        Route::post('projects/{project}/storage/{bucket}/folders', [ProjectStorageController::class, 'folder'])->name('projects.storage.folder');
        Route::post('projects/{project}/storage/{bucket}/agent', [ProjectStorageController::class, 'agent'])->name('projects.storage.agent');
        Route::get('projects/{project}/developer/networking', [ProjectDeveloperController::class, 'networking'])->name('projects.developer.networking');
        Route::get('projects/{project}/developer/usage', [ProjectDeveloperController::class, 'usage'])->name('projects.developer.usage');
        Route::get('projects/{project}/developer/storage', [ProjectDeveloperController::class, 'storage'])->name('projects.developer.storage');
        Route::get('projects/{project}/developer/ssh', [ProjectDeveloperController::class, 'ssh'])->name('projects.developer.ssh');
        Route::get('ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
        Route::post('ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
        Route::delete('ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');
        Route::patch('projects/{project}/agent', [ProjectAgentController::class, 'update'])->name('projects.agent.update');
        Route::get('projects/{project}/claude-login', [ClaudeLoginController::class, 'show'])->middleware('throttle:30,1')->name('projects.claude-login.show');
        Route::post('projects/{project}/claude-login/resume', [ClaudeLoginController::class, 'resume'])->middleware('throttle:30,1')->name('projects.claude-login.resume');
        Route::patch('projects/{project}/agent/autofix', [ProjectAgentController::class, 'autofix'])->name('projects.agent.autofix');
        Route::post('projects/{project}/agent/stop', [ProjectAgentController::class, 'stop'])->name('projects.agent.stop');
        Route::delete('projects/{project}/messages/{message}', [ProjectMessageController::class, 'destroy'])->name('projects.messages.destroy');
        Route::get('projects/{project}/board', [TaskController::class, 'index'])->name('projects.board');
        Route::get('projects/{project}/tasks/new', [TaskController::class, 'create'])->name('projects.tasks.create');
        Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
        Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show'])->scopeBindings()->name('projects.tasks.show');
        Route::patch('projects/{project}/tasks/{task}', [TaskController::class, 'update'])->scopeBindings()->name('projects.tasks.update');
        Route::delete('projects/{project}/tasks/{task}', [TaskController::class, 'destroy'])->scopeBindings()->name('projects.tasks.destroy');
        Route::post('projects/{project}/tasks/{task}/messages', [TaskMessageController::class, 'store'])->scopeBindings()->name('projects.tasks.messages.store');
        Route::delete('projects/{project}/tasks/{task}/messages/{message}', [TaskMessageController::class, 'destroy'])->name('projects.tasks.messages.destroy');
        Route::post('projects/{project}/tasks/{task}/stop', [TaskMessageController::class, 'stop'])->scopeBindings()->name('projects.tasks.stop');
        Route::post('projects/{project}/tasks/{task}/apply', [TaskController::class, 'apply'])->scopeBindings()->name('projects.tasks.apply');
        Route::post('projects/{project}/tasks/{task}/update-from-main', [TaskController::class, 'updateFromMain'])->scopeBindings()->name('projects.tasks.update-from-main');
        Route::get('agent-models', [AgentModelController::class, 'index'])->name('agent-models.index');
        Route::put('agent-models/favorites', [AgentModelController::class, 'favorite'])->name('agent-models.favorite');
        Route::post('projects/{project}/publication', [ProjectPublicationController::class, 'store'])->name('projects.publication.store');
        Route::delete('projects/{project}/publication', [ProjectPublicationController::class, 'destroy'])->name('projects.publication.destroy');
    });

    Route::get('usage', [UsageController::class, 'index'])->name('usage.index');

    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::resource('groups', GroupController::class)->except(['create', 'edit']);
    Route::post('groups/{group}/members', [GroupMemberController::class, 'store'])->name('groups.members.store');
    Route::patch('groups/{group}/members/{user}', [GroupMemberController::class, 'update'])->name('groups.members.update');
    Route::delete('groups/{group}/members/{user}', [GroupMemberController::class, 'destroy'])->name('groups.members.destroy');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    // Install-wide settings (ADMIN-001 to ADMIN-005).
    Route::middleware('can:administer')->prefix('admin')->name('admin.')->group(function () {
        Route::get('general', [BrandingController::class, 'edit'])->name('general.edit');
        Route::patch('general', [BrandingController::class, 'update'])->name('general.update');
        Route::post('general/logo', [BrandingController::class, 'storeLogo'])->name('general.logo.store');
        Route::delete('general/logo', [BrandingController::class, 'destroyLogo'])->name('general.logo.destroy');

        Route::get('sandboxes', [SandboxProviderController::class, 'index'])->name('sandboxes.index');
        Route::put('sandboxes/order', [SandboxProviderController::class, 'reorder'])->name('sandboxes.reorder');
        Route::put('sandboxes/{provider}', [SandboxProviderController::class, 'update'])->name('sandboxes.update');

        Route::get('monitoring', [ServerMonitoringController::class, 'show'])->name('monitoring.show');

        Route::get('server', [ServerController::class, 'edit'])->name('server.edit');
        Route::put('server', [ServerController::class, 'update'])->name('server.update');

        Route::get('backups', [DatabaseBackupController::class, 'index'])->name('backups.index');
        Route::put('backups', [DatabaseBackupController::class, 'update'])->name('backups.update');
        Route::post('backups', [DatabaseBackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}', [DatabaseBackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{backup}', [DatabaseBackupController::class, 'destroy'])->name('backups.destroy');
        Route::post('backups/{backup}/restore', [DatabaseBackupController::class, 'restore'])->name('backups.restore');
    });
});

require __DIR__.'/settings.php';
