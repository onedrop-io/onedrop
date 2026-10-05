<?php

namespace App\Models;

use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An SSH public key a user signs in to their projects' sandboxes with (Tools → Developer → SSH).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $public_key
 * @property string $fingerprint
 * @property int|null $desktop_token_id The desktop app sign-in that made it for its computer (DESK-008)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'public_key', 'fingerprint', 'desktop_token_id'])]
class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    /**
     * Key types sshd accepts that are still considered safe.
     */
    public const TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /**
     * Split an OpenSSH public key line ("type base64 comment") into its parts, or null when it isn't one.
     * The key data must decode and name the same type it's labelled with.
     *
     * @return array{type: string, key: string, comment: string|null, fingerprint: string}|null
     */
    public static function parse(string $line): ?array
    {
        $parts = preg_split('/\s+/', trim($line), 3);

        if ($parts === false || count($parts) < 2 || ! in_array($parts[0], self::TYPES, true)) {
            return null;
        }

        $blob = base64_decode($parts[1], true);

        if ($blob === false || strlen($blob) < 4) {
            return null;
        }

        $header = unpack('N', substr($blob, 0, 4));

        if ($header === false || substr($blob, 4, $header[1]) !== $parts[0]) {
            return null;
        }

        return [
            'type' => $parts[0],
            'key' => $parts[1],
            'comment' => isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : null,
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
        ];
    }

    /**
     * The key's type, e.g. "ssh-ed25519".
     */
    public function type(): string
    {
        return explode(' ', $this->public_key, 2)[0];
    }

    /**
     * The user the key belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
