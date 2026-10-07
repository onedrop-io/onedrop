<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JoinOrganizationController extends Controller
{
    /**
     * Join an organization that verified the domain of the user's email (ORG-008). Any other is a 404.
     */
    public function __invoke(Request $request, Organization $joinable): RedirectResponse
    {
        abort_unless(Organization::joinableBy($request->user())->contains($joinable), 404);

        $joinable->addMember($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You joined :name.', ['name' => $joinable->name])]);

        return to_route('organizations.home', $joinable);
    }
}
