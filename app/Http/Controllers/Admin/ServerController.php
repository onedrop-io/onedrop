<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Sandbox\ServerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's domain and certificates (ADMIN-004).
 */
class ServerController extends Controller
{
    /**
     * Show the Server settings.
     */
    public function edit(ServerSettings $server): Response
    {
        return Inertia::render('admin/server', [
            'install' => $server->install(),
            'editable' => $server->editable(),
            'current' => $server->current(),
            'requested' => $server->requested(),
        ]);
    }

    /**
     * Ask the server to move to a domain (applied in the background by a systemd unit on server installs).
     */
    public function update(Request $request, ServerSettings $server): RedirectResponse
    {
        abort_unless($server->editable(), 403, __("This install's address is set where it's deployed."));

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'email' => ['nullable', 'email:strict', 'max:254', 'regex:/^[^\s"\'\\\\`$]+$/'],
            'certificates' => ['required', Rule::in(ServerSettings::CERTIFICATES)],
        ], [
            'domain.regex' => __('Enter a domain name, like onedrop.example.com.'),
        ]);

        $server->request($validated['domain'], $validated['email'] ?? null, $validated['certificates']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. The server is applying it.')]);

        return to_route('admin.server.edit');
    }
}
