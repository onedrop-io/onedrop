<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
