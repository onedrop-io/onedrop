<?php

namespace App\Enums;

/**
 * Something made for a hosted project (HOST-001, HOST-002), one of each at most.
 */
enum HostedServiceKind: string
{
    /** The Fly app its machines run in. */
    case App = 'app';

    /** A Fly volume for data that has no managed service (SQLite, App Storage, other data folders). */
    case Volume = 'volume';

    /** A Neon Postgres database. */
    case Postgres = 'postgres';

    /** An Upstash Redis database. */
    case Redis = 'redis';

    /** A Cloudflare R2 bucket, for apps that use the S3 API. */
    case Bucket = 'bucket';

    /** A Cloudflare Worker serving a front end's files. */
    case Site = 'site';

    /** An R2 bucket Litestream backs the app's SQLite databases up to, continuously (HOST-008). */
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::App => 'App',
            self::Volume => 'Data volume',
            self::Postgres => 'Postgres',
            self::Redis => 'Redis',
            self::Bucket => 'File storage (S3)',
            self::Site => 'Static site',
            self::Backup => 'SQLite backups',
        };
    }

    /**
     * Whether it holds the app's data (deleted only when the user asks, HOST-002).
     */
    public function holdsData(): bool
    {
        return in_array($this, [self::Volume, self::Postgres, self::Redis, self::Bucket, self::Backup], true);
    }
}
