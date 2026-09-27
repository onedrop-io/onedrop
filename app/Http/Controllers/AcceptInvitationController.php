<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AcceptInvitationController extends Controller
{
    /**
     * Session key holding the invite being accepted during sign-up.
     */
    public const SESSION_KEY = 'invitation_id';

    /**
     * Open an invite link: remember it and send the person to sign-up.
     */
    public function __invoke(Request $request, string $token): SymfonyResponse
    {
        if ($request->user()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __("You're already signed in.")]);

            return to_route('dashboard');
        }

        $invitation = Invitation::findByToken($token);

        if (! $invitation?->isUsable()) {
            return Inertia::render('auth/invitation-invalid', [
                'reason' => match ($invitation?->status()) {
                    'accepted' => __('This invite has already been used.'),
                    'expired' => __('This invite has expired. Ask for a new one.'),
                    'revoked' => __('This invite was cancelled. Ask for a new one.'),
                    default => __("This invite link isn't valid. Check you copied all of it."),
                },
            ])->toResponse($request)->setStatusCode(410);
        }

        $request->session()->put(self::SESSION_KEY, $invitation->id);

        return to_route('register');
    }
}
