<?php

namespace App\Tables;

use InvalidArgumentException;

/**
 * The app's tables, from config('tables.tables').
 */
class Tables
{
    /**
     * @return array<string, class-string<Table>>
     */
    public static function classes(): array
    {
        return config('tables.tables', []);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::classes());
    }

    public static function has(string $key): bool
    {
        return isset(self::classes()[$key]);
    }

    public static function find(string $key): ?Table
    {
        $class = self::classes()[$key] ?? null;

        return $class === null ? null : app($class)->setKey($key);
    }

    public static function get(string $key): Table
    {
        return self::find($key) ?? throw new InvalidArgumentException("There's no table [{$key}] in config('tables.tables').");
    }

    /**
     * The table, or a 404.
     */
    public static function findOrFail(string $key): Table
    {
        $table = self::find($key);

        abort_if($table === null, 404);

        return $table;
    }
}
