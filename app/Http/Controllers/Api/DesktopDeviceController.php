<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Sandbox\DesktopTunnel;
use App\Sandbox\Providers\DeviceSandboxProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopDeviceController extends Controller
{
    /**
     * This computer (DESK-010): its id and name, and a ticket for the relay that lets the app reach the projects
     * running on it. 404 when the install can't run projects on computers (no Cloudflare gateway).
     */
    public function store(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! DeviceSandboxProvider::available()) {
            return response()->json([
                'id' => $token->getKey(),
                'name' => $token->name,
                'message' => __('This OneDrop can\'t run projects on computers.'),
            ], 404);
        }

        $ticket = DesktopTunnel::sign((string) config('sandbox.gateway_secret'), [
            'd' => $token->getKey(),
            'exp' => now()->addSeconds(DesktopTunnel::TICKET_SECONDS)->getTimestamp(),
        ]);
        $relay = preg_replace('#^http#', 'ws', DeviceSandboxProvider::relayUrl());

        return response()->json([
            'id' => $token->getKey(),
            'name' => $token->name,
            'url' => "{$relay}/__onedrop/devices/{$token->getKey()}/connect?".http_build_query(['ticket' => $ticket]),
            'image' => config('sandbox.providers.device.image'),
        ])->header('Cache-Control', 'no-store');
    }
}
