<?php

namespace App\Sandbox\Agents;

use Illuminate\Support\Str;

/**
 * The agent's latest step in words anyone can follow, for the sidebar and the board (PRJ-006): "Building the
 * orders page" rather than "Creating resources/js/pages/admin/orders/show.tsx". The chat keeps the exact step.
 */
class PlainActivity
{
    /** File names that say what kind of page it is, not which one ("orders/show.tsx" is the orders page). */
    protected const GENERIC_NAMES = ['index', 'show', 'edit', 'create', 'new', 'page', 'layout', 'view', 'list', 'detail', 'details', 'form', 'app', 'main', 'default', 'root'];

    /**
     * Commands, by what they're for. The first match wins, so the more specific ones come first.
     *
     * @var array<string, list<string>>
     */
    protected const COMMANDS = [
        'Testing the app' => ['run-tests', 'playwright', 'pest', 'phpunit', 'vitest', 'jest', 'npm test', 'npm run test', 'artisan test'],
        'Setting up the project' => ['laravel new', 'create vite', 'create-vite', 'create-next-app', 'npm create', 'npx create', 'composer create-project'],
        'Installing tools' => ['npm install', 'npm i ', 'npm ci', 'composer require', 'composer install', 'composer update', 'pip install', 'yarn add', 'pnpm add', 'apt-get'],
        'Restarting the preview' => ['/opt/onedrop/restart', 'npm run dev', 'artisan serve'],
        'Building the app' => ['npm run build', 'vite build', 'tsc'],
        'Updating the database' => ['migrate', 'db:seed', 'sqlite3', 'psql', 'mysql '],
        'Checking the app works' => ['curl', 'errors.log', 'server.log', '/opt/onedrop/browser', 'screenshot'],
        'Saving progress' => ['git '],
        'Looking through the code' => ['ls ', 'ls', 'cat ', 'find ', 'rg ', 'grep ', 'fd ', 'head ', 'tail ', 'tree', 'wc '],
    ];

    /**
     * Words in a step the agent described itself (Claude Code's "Install dependencies"), by what they're for.
     *
     * @var array<string, string>
     */
    protected const DESCRIPTIONS = [
        'Testing the app' => '/\btests?\b|\btesting\b/',
        'Installing tools' => '/\binstall/',
        'Setting up the project' => '/\bscaffold|\bcreate (a |the )?(new )?(project|app)\b/',
        'Restarting the preview' => '/\brestart|dev server|\bpreview\b/',
        'Building the app' => '/\bbuild|\bcompile/',
        'Updating the database' => '/\bmigrat|\bdatabase\b|\bseed/',
        'Checking the app works' => '/\bcheck|\bverify|\bconfirm|\berrors?\b|\blogs?\b/',
        'Saving progress' => '/\bcommit|\bgit\b/',
        'Looking through the code' => '/\blist|\bfind|\bsearch|\blook|\bread|\bshow\b/',
    ];

    /**
     * The plain-language version of an activity line (or null for none).
     */
    public static function describe(?string $activity): ?string
    {
        if ($activity === null || trim($activity) === '') {
            return $activity;
        }

        // A step that failed is usually retried straight away; saying so only worries.
        $activity = preg_replace('/ \(failed\)$/', '', $activity);

        if (preg_match('/^(Creating|Editing|Reading) (.+)$/', $activity, $match) === 1) {
            return self::file($match[1], trim($match[2]));
        }

        if (str_starts_with($activity, 'Running ')) {
            return self::command(Str::after($activity, 'Running '));
        }

        // A tool the harness only names ("Using mcp__…") means nothing to a non-developer.
        if (str_starts_with($activity, 'Using ')) {
            return 'Working on the app';
        }

        return $activity;
    }

    protected static function file(string $verb, string $path): string
    {
        $path = preg_replace('#^\./#', '', Str::after($path, '/workspace/'));
        $lower = strtolower($path);
        $file = pathinfo($path, PATHINFO_FILENAME);
        $name = strtolower($file);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($verb === 'Reading') {
            return $lower === '.onedrop/req.md' ? 'Reading your requirements' : 'Reading the code';
        }

        return match (true) {
            $lower === '.onedrop/req.md' => 'Noting what you asked for',
            str_starts_with($lower, '.onedrop/') => 'Setting up the preview',
            str_contains($lower, 'tests/') || str_contains($name, '.spec') || str_contains($name, '.test') => 'Writing tests',
            str_contains($lower, 'seeders/') || str_contains($lower, 'factories/') => 'Adding sample data',
            str_contains($lower, 'migrations/') || $extension === 'sql' || str_contains($lower, 'schema') => 'Setting up the database',
            str_contains($lower, 'models/') => 'Setting up '.Str::plural(self::words($file)),
            in_array($extension, ['css', 'scss', 'sass', 'less'], true) || str_starts_with($name, 'tailwind') => 'Styling the app',
            str_contains($name, 'favicon') || str_contains($lower, 'app-logo') => 'Updating the app icon',
            in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'], true) => 'Adding images',
            ($page = self::pageName($path)) !== null => "Building the {$page} page",
            str_contains($lower, 'components/') => 'Building the '.self::words($file),
            str_starts_with($lower, 'routes/') => 'Connecting the pages',
            str_contains($lower, 'controllers/') && ($subject = Str::beforeLast($file, 'Controller')) !== '' => 'Working on the '.self::words($subject),
            str_starts_with(basename($lower), '.env') || in_array($name, ['package', 'composer', 'vite.config', 'tsconfig'], true) || str_starts_with($lower, 'config/') => 'Updating settings',
            $extension === 'md' => 'Writing notes',
            default => $verb === 'Creating' ? 'Writing code' : 'Making changes',
        };
    }

    /**
     * Which page a file is, from the folders under `pages/` (or `views/`, `app/` for Next.js), skipping names
     * that only say what kind of page it is.
     */
    protected static function pageName(string $path): ?string
    {
        if (preg_match('#(?:^|/)(?:pages|views|routes|app)/(.+)$#i', $path, $match) !== 1 || ! preg_match('/\.(tsx|jsx|vue|svelte|blade\.php|astro)$/i', $path)) {
            return null;
        }

        $parts = collect(explode('/', preg_replace('/\.(tsx|jsx|vue|svelte|blade\.php|astro)$/i', '', $match[1])))
            ->map(fn (string $part) => strtolower(trim($part, '[]()_')))
            ->reject(fn (string $part) => $part === '' || in_array($part, self::GENERIC_NAMES, true) || str_ends_with($part, 'id') && strlen($part) <= 4);

        $page = $parts->last();

        return $page === null ? 'home' : self::words($page === 'welcome' ? 'home' : $page);
    }

    protected static function command(string $command): string
    {
        $described = ! str_starts_with($command, '`');
        $lower = strtolower(trim($command, " `\t"));

        if ($described) {
            foreach (self::DESCRIPTIONS as $description => $pattern) {
                if (preg_match($pattern, $lower) === 1) {
                    return $description;
                }
            }
        }

        foreach (self::COMMANDS as $description => $patterns) {
            foreach ($patterns as $pattern) {
                if ($lower === trim($pattern) || str_contains($lower, $pattern)) {
                    return $description;
                }
            }
        }

        return 'Working on the app';
    }

    /**
     * "cart-drawer", "cartDrawer" or "cart_drawer" as "cart drawer".
     */
    protected static function words(string $name): string
    {
        return Str::of($name)->snake(' ')->replace(['-', '_', '.'], ' ')->squish()->lower()->toString();
    }
}
