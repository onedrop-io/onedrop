<?php

use App\Http\Controllers\Api\AgentModelController;
use App\Http\Controllers\Api\AiCreditsController;
use App\Http\Controllers\Api\BroadcastingController;
use App\Http\Controllers\Api\ClaudeLoginController;
use App\Http\Controllers\Api\DesktopTokenController;
use App\Http\Controllers\Api\GitHubAppController;
use App\Http\Controllers\Api\ProjectAgentController;
use App\Http\Controllers\Api\ProjectAttachmentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectIconController;
use App\Http\Controllers\Api\ProjectMessageController;
use App\Http\Controllers\Api\SandboxActivityController;
use App\Http\Controllers\Api\TemplateScreenshotController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// The desktop app's API (DESK-001). Bearer tokens only; the web app keeps its own routes and never calls these.
Route::prefix('v1')->name('api.')->group(function () {
    // The last step of signing in: a one-time code from the browser, and the app's PKCE verifier, for a token.
    Route::post('desktop/token', [DesktopTokenController::class, 'store'])->middleware('throttle:20,1')->name('desktop.token.store');

    // `organization`: a project's own organization on its routes, else the one the user works in (ORG-002).
    // One rate limit for everything: `throttle` counts all of a user's requests under one key, so a lower limit on one
    // route would be used up by the app's other requests.
    Route::middleware(['auth:sanctum', 'organization', 'throttle:600,1'])->group(function () {
        Route::delete('desktop/token', [DesktopTokenController::class, 'destroy'])->name('desktop.token.destroy');
        Route::get('user', [UserController::class, 'show'])->name('user.show');
        Route::put('user/organization', [UserController::class, 'switchOrganization'])->name('user.organization.update');
        Route::post('broadcasting/auth', [BroadcastingController::class, 'authenticate'])->name('broadcasting.auth');

        Route::middleware('agent.connected')->group(function () {
            Route::get('agent-models', [AgentModelController::class, 'index'])->name('agent-models.index');
            Route::put('agent-models/favorites', [AgentModelController::class, 'favorite'])->name('agent-models.favorite');

            Route::get('github/repositories', [GitHubAppController::class, 'importable'])->name('github.repositories');

            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/new', [ProjectController::class, 'create'])->name('projects.create');
            Route::get('projects/new/apps', [ProjectController::class, 'apps'])->name('projects.create.apps');
            Route::get('projects/new/featured', [ProjectController::class, 'featured'])->name('projects.create.featured');
            Route::get('templates/screenshots', TemplateScreenshotController::class)->name('templates.screenshots');
            Route::get('ai-credits', AiCreditsController::class)->name('ai-credits');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
            Route::get('projects/{project}/icon', [ProjectIconController::class, 'show'])->name('projects.icon.show');
            Route::post('projects/{project}/messages', [ProjectMessageController::class, 'store'])->name('projects.messages.store');
            Route::delete('projects/{project}/messages/{message}', [ProjectMessageController::class, 'destroy'])->name('projects.messages.destroy');
            Route::get('projects/{project}/attachments/{attachment}', [ProjectAttachmentController::class, 'show'])->name('projects.attachments.show');
            Route::patch('projects/{project}/agent', [ProjectAgentController::class, 'update'])->name('projects.agent.update');
            Route::post('projects/{project}/agent/stop', [ProjectAgentController::class, 'stop'])->name('projects.agent.stop');
            Route::post('projects/{project}/sandbox/activity', [SandboxActivityController::class, 'store'])->name('projects.sandbox.activity');
            Route::get('projects/{project}/claude-login', [ClaudeLoginController::class, 'show'])->name('projects.claude-login.show');
            Route::post('projects/{project}/claude-login/resume', [ClaudeLoginController::class, 'resume'])->name('projects.claude-login.resume');
        });
    });
});
