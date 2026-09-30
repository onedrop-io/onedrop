<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * One group of install-wide settings an admin changed (ADMIN-001 to ADMIN-005), e.g. "branding" or
 * "sandboxes". Values are stored encrypted, since some hold API keys. SystemConfig lays them over config/.
 *
 * @property string $key
 * @property array<string, mixed> $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<string, array<string, mixed>>|null every group, read once per process */
    protected static ?array $loaded = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted:array',
        ];
    }

    /**
     * A group of settings, or [] when it was never saved (or the table isn't migrated yet).
     *
     * @return array<string, mixed>
     */
    public static function group(string $key): array
    {
        return static::groups()[$key] ?? [];
    }

    /**
     * Save a group of settings, replacing what was there.
     *
     * @param  array<string, mixed>  $value
     */
    public static function put(string $key, array $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::$loaded = null;
    }

    /**
     * Merge values into a group of settings.
     *
     * @param  array<string, mixed>  $values
     */
    public static function merge(string $key, array $values): void
    {
        static::put($key, array_replace(static::group($key), $values));
    }

    /**
     * Every saved group, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        if (static::$loaded !== null) {
            return static::$loaded;
        }

        try {
            return static::$loaded = static::query()->get()->mapWithKeys(fn (self $setting) => [$setting->key => $setting->value])->all();
        } catch (QueryException) {
            // Before the first migration (e.g. while `php artisan migrate` boots): nothing's saved yet.
            return [];
        }
    }

    /**
     * Read the settings again on next use (tests, and long-running processes after a change).
     */
    public static function flush(): void
    {
        static::$loaded = null;
    }
}
