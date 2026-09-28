<?php

use App\Models\SshKey;

const ED25519_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPIBiZ++49W3bv5jUEcyynm2S7IIDJcUcE9sM1vFHKsa';

test('it parses an OpenSSH public key with the same fingerprint as ssh-keygen', function () {
    expect(SshKey::parse('  '.ED25519_KEY." jeff@laptop  \n"))->toBe([
        'type' => 'ssh-ed25519',
        'key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIPIBiZ++49W3bv5jUEcyynm2S7IIDJcUcE9sM1vFHKsa',
        'comment' => 'jeff@laptop',
        // `ssh-keygen -lf` prints SHA256:ImC8H8npCwPtG6OXskOMrY0meQbMijXXJ6h+avSBuIM for this key.
        'fingerprint' => 'SHA256:ImC8H8npCwPtG6OXskOMrY0meQbMijXXJ6h+avSBuIM',
    ]);
})->group('DEVTOOLS-001');

test('it rejects things that are not public keys', function (string $line) {
    expect(SshKey::parse($line))->toBeNull();
})->with([
    'empty' => [''],
    'type only' => ['ssh-ed25519'],
    'unsupported type' => ['ssh-dss AAAAB3NzaC1kc3MAAACBAP'],
    'not base64' => ['ssh-ed25519 not*base64!'],
    'mislabelled type' => ['ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIPIBiZ++49W3bv5jUEcyynm2S7IIDJcUcE9sM1vFHKsa'],
    'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----"],
])->group('DEVTOOLS-001');
