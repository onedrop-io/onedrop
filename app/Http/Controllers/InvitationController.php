<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * List the user's invites to the organization (all of its invites for its admins).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = ResolveOrganization::current($request);

        $invitations = $organization->invitations()
            ->with(['acceptedBy:id,name', 'inviter:id,name'])
            ->when(! $organization->isManagedBy($user), fn ($query) => $query->where('invited_by', $user->id))
            ->latest('id')
            ->limit(100)
            ->get();

        return Inertia::render('invitations/index', [
            'invitations' => $invitations->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'status' => $invitation->status(),
                'url' => $invitation->isUsable() ? $invitation->url() : null,
                'invited_by' => $invitation->inviter?->name,
                'accepted_by' => $invitation->acceptedBy?->name,
                'created_at' => $invitation->created_at?->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create an invite link.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $invitation = Invitation::issue($request->user(), $validated['email'] ?? null, ResolveOrganization::current($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invite link created. Copy it and send it to them.')]);

        return to_route('invitations.index', $invitation->organization);
    }

    /**
     * Revoke an unused invite.
     */
    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_unless($invitation->invited_by === $request->user()->id || $invitation->organization->isManagedBy($request->user()), 403);

        if ($invitation->isUsable()) {
            $invitation->update(['revoked_at' => now()]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Invite revoked.')]);
        }

        return to_route('invitations.index', $invitation->organization);
    }
}
