<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Go to the new-project page of the organization the user used last (ORG-002), keeping anything flashed for it.
     */
    public function current(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return to_route('organizations.home', ResolveOrganization::current($request));
    }
}
