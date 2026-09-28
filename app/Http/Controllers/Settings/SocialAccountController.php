<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SocialAccountController extends Controller
{
    /**
     * Disconnect a provider, as long as the user can still log in another way.
     */
    public function destroy(Request $request, SocialAccount $socialAccount): RedirectResponse
    {
        $user = $request->user();

        abort_unless($socialAccount->user_id === $user->id, 404);

        if ($user->loginMethodCount() <= 1) {
            return back()->withErrors(['social' => __('Set a password or connect another account before disconnecting :provider.', [
                'provider' => $socialAccount->provider->label(),
            ])]);
        }

        $socialAccount->delete();

        // Its picture goes with it; fall back to another connected provider's.
        if ($user->avatar !== null && $user->avatar === $socialAccount->avatar) {
            $user->forceFill(['avatar' => $user->socialAccounts()->whereNotNull('avatar')->value('avatar')])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider disconnected.', ['provider' => $socialAccount->provider->label()])]);

        return back();
    }
}
