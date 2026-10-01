<?php

namespace App\Http\Controllers;

use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Admins signing in as another user to see what they see (USR-003), and signing back in as themselves.
 */
class ImpersonationController extends Controller
{
    /**
     * Sign in as the user (admins only, never as themselves or another admin).
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_if($admin->is($user), 403, __('You are already signed in as yourself.'));
        abort_if($user->is_admin, 403, __('Admins cannot be impersonated.'));
        abort_if($request->session()->has(Impersonation::SESSION_KEY), 403, __('Stop impersonating first.'));

        $impersonation = Impersonation::create([
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'started_at' => now(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $impersonation->id);

        return redirect()->route('dashboard');
    }

    /**
     * Stop impersonating and sign back in as the admin.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $impersonation = Impersonation::current();
        abort_if($impersonation === null, 404);

        $impersonation->end();
        $request->session()->forget(Impersonation::SESSION_KEY);

        if ($impersonation->admin === null) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        Auth::login($impersonation->admin);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are signed in as yourself again.')]);

        return redirect()->route('users.show', $impersonation->user_id);
    }
}
