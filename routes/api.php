<?php

use App\Http\Controllers\Api\DesktopTokenController;
use Illuminate\Support\Facades\Route;

// The desktop app's sign-in (DESK-001). With its token, the app then uses the web app's own routes (UseDesktopToken).
Route::prefix('v1')->name('api.')->group(function () {
    // The last step of signing in: a one-time code from the browser, and the app's PKCE verifier, for a token.
    Route::post('desktop/token', [DesktopTokenController::class, 'store'])->middleware('throttle:20,1')->name('desktop.token.store');

    Route::middleware(['auth:sanctum', 'throttle:600,1'])->group(function () {
        Route::delete('desktop/token', [DesktopTokenController::class, 'destroy'])->name('desktop.token.destroy');
    });
});
