<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin signed in as another user (USR-003), kept as an audit trail.
 *
 * @property int $id
 * @property int|null $admin_id Null once the admin's account is deleted
 * @property int $user_id
 * @property string|null $ip_address
 * @property Carbon $started_at
 * @property Carbon|null $ended_at Null while it's going on, or when the session ended without stopping
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['admin_id', 'user_id', 'ip_address', 'started_at', 'ended_at'])]
class Impersonation extends Model
{
    /** Session key holding the impersonation's id while an admin is signed in as someone else. */
    public const SESSION_KEY = 'impersonation_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The impersonation going on in this session, if any.
     */
    public static function current(): ?self
    {
        $id = session(self::SESSION_KEY);

        return $id ? static::query()->with('admin')->whereKey($id)->first() : null;
    }

    /**
     * The admin who signed in as the user.
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * The user they signed in as.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark it over.
     */
    public function end(): void
    {
        if ($this->ended_at === null) {
            $this->forceFill(['ended_at' => now()])->save();
        }
    }
}
