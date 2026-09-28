<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSshKeyRequest;
use App\Jobs\SyncSshKeys;
use App\Models\SshKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SSH public keys on the user's account (Tools → Developer → SSH → Keys).
 */
class SshKeyController extends Controller
{
    /**
     * The user's keys, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'keys' => $request->user()->sshKeys()->latest('id')->get()->map($this->present(...)),
        ]);
    }

    /**
     * Add a key and put it into the user's running sandboxes.
     */
    public function store(StoreSshKeyRequest $request): JsonResponse
    {
        $parsed = SshKey::parse($request->validated('public_key'));
        $name = trim((string) $request->validated('name')) ?: ($parsed['comment'] ?? $parsed['type']);

        $key = $request->user()->sshKeys()->create([
            'name' => mb_substr($name, 0, 100),
            'public_key' => "{$parsed['type']} {$parsed['key']}",
            'fingerprint' => $parsed['fingerprint'],
        ]);

        SyncSshKeys::dispatch($request->user());

        return response()->json(['key' => $this->present($key)], 201);
    }

    /**
     * Delete a key and take it out of the user's running sandboxes.
     */
    public function destroy(Request $request, SshKey $sshKey): JsonResponse
    {
        abort_unless($sshKey->user_id === $request->user()->id, 404);

        $sshKey->delete();

        SyncSshKeys::dispatch($request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{id: int, name: string, type: string, fingerprint: string, created_at: string|null}
     */
    protected function present(SshKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'type' => $key->type(),
            'fingerprint' => $key->fingerprint,
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}
