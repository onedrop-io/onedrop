<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OpenRouterAuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectLogController;
use App\Http\Controllers\ProjectMessageController;
use App\Http\Controllers\ProjectPublicationController;
use App\Http\Controllers\SandboxEventController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('invite/{token}', AcceptInvitationController::class)->name('invitations.accept');

// Called by the agent forwarder inside a sandbox; authenticated by a per-sandbox bearer token.
Route::post('sandbox-events/{sandbox}', [SandboxEventController::class, 'store'])
    ->middleware('throttle:600,1')
    ->name('sandbox-events.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding/ai', [OnboardingController::class, 'ai'])->name('onboarding.ai');
    Route::get('auth/openrouter', [OpenRouterAuthController::class, 'redirect'])->name('openrouter.redirect');
    Route::get('auth/openrouter/callback', [OpenRouterAuthController::class, 'callback'])->name('openrouter.callback');

    Route::middleware('agent.connected')->group(function () {
        Route::get('dashboard', [ProjectController::class, 'create'])->name('dashboard');

        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::post('projects/{project}/messages', [ProjectMessageController::class, 'store'])->name('projects.messages.store');
        Route::get('projects/{project}/files', [ProjectFileController::class, 'index'])->name('projects.files.index');
        Route::get('projects/{project}/files/show', [ProjectFileController::class, 'show'])->name('projects.files.show');
        Route::get('projects/{project}/logs', [ProjectLogController::class, 'index'])->name('projects.logs.index');
        Route::post('projects/{project}/publication', [ProjectPublicationController::class, 'store'])->name('projects.publication.store');
        Route::delete('projects/{project}/publication', [ProjectPublicationController::class, 'destroy'])->name('projects.publication.destroy');
    });

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
});

require __DIR__.'/settings.php';
