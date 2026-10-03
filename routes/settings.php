<?php

use App\Http\Controllers\Settings\AgentConnectionController;
use App\Http\Controllers\Settings\BuildModeController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SocialAccountController;
use App\Http\Middleware\RequirePasswordIfSet;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePasswordIfSet::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::delete('settings/connected-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('social-accounts.destroy');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    Route::inertia('settings/notifications', 'settings/notifications')->name('notifications.edit');
    Route::put('settings/build-mode', BuildModeController::class)->name('build-mode.update');

    Route::get('settings/ai', [AgentConnectionController::class, 'index'])->name('agent-connections.index');
    Route::post('settings/ai', [AgentConnectionController::class, 'store'])->name('agent-connections.store');
    Route::post('settings/ai/ollama-server', [AgentConnectionController::class, 'ollamaServer'])->middleware('throttle:10,1')->name('agent-connections.ollama-server');
    Route::post('settings/ai/claude-login', [AgentConnectionController::class, 'claudeLogin'])->name('agent-connections.claude-login');
    Route::patch('settings/ai/{connection}', [AgentConnectionController::class, 'update'])->name('agent-connections.update');
    Route::delete('settings/ai/{connection}', [AgentConnectionController::class, 'destroy'])->name('agent-connections.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
