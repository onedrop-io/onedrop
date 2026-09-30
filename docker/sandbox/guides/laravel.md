# Starting a new app on Laravel

The default for every new app (unless the user names another stack). Laravel's React starter kit
comes with sign-in, a SQLite database, a database-backed queue, cache and sessions, and file storage;
with Reverb added below it also has realtime updates, so apps can grow into all of them without being
rewritten.

## Set it up

/workspace may already hold `.onedrop/` (attachments), so create the app next door and copy it in:

```bash
composer create-project laravel/react-starter-kit /tmp/app --no-interaction --prefer-dist
cp -a /tmp/app/. /workspace/ && rm -rf /tmp/app
cd /workspace && npm install --no-audit --no-fund && php artisan migrate --force
php artisan install:broadcasting --reverb --without-node --no-interaction
npm install --no-audit --no-fund laravel-echo pusher-js @laravel/echo-react
```

`install:broadcasting` adds a `configureEcho({ broadcaster: 'reverb' })` call to `resources/js/app.tsx`,
which points browsers at `localhost:8080`. Visitors can't reach that, so connect to the page's own
address instead (the sandbox's proxy passes `/app` on to Reverb):

```ts
configureEcho({
    broadcaster: 'reverb',
    wsHost: window.location.hostname,
    wsPort: Number(window.location.port) || 80,
    wssPort: Number(window.location.port) || 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
});
```

Write `/workspace/.onedrop/routes.json` so the proxy sends Reverb's WebSockets to it:

```json
{ "/app": 8080 }
```

Then create `/workspace/.onedrop/dev` (executable) and run `/opt/onedrop/restart`:

```bash
#!/usr/bin/env bash
# Assets are built on every change instead of served by Vite's dev server: the preview only
# reaches $PORT, and the preview reloads itself when you finish. The old build stays while a new
# one is written, so pages opened mid-build still load.
rm -f public/hot
npx vite build --watch --emptyOutDir=false &
php artisan queue:listen --tries=1 &
php artisan reverb:start --host=127.0.0.1 --port=8080 &
exec php artisan serve --host=0.0.0.0 --port="$PORT"
```

In `bootstrap/app.php`, trust the preview's proxy: `$middleware->trustProxies(at: '*');`.

## Building on it

- Keep the starter kit's layout, components (`resources/js/components/ui`) and sign-in pages; build
  the app's own pages inside its signed-in layout, and replace the welcome page with the app's own
  home page.
- Model the user's records as Eloquent models with migrations, factories and a seeder with realistic
  sample data (`php artisan make:model Deal -mfs`), and run `php artisan migrate --seed`.
- Long-running work (emails, imports, calling other services) goes in queued jobs; the queue worker is
  already running.
- Things other people change (new records, status changes, comments, chat) should appear without a
  reload: broadcast an event (`ShouldBroadcast`, on a private channel authorized in
  `routes/channels.php` when the data isn't public) and listen with `useEcho` from
  `@laravel/echo-react`, or `useEchoPublic` for public channels.
- Uploaded files go in App Storage (/opt/onedrop/guides/storage.md), not `storage/app`.
- If people sign in to the app, follow /opt/onedrop/guides/auth.md so Tools → Users & Auth can manage them.
  If the app is public and has no accounts, remove the sign-in and register links from its pages.
- Write tests for what you build and run `php artisan test`.
- After changing `.onedrop/dev`, `.env` or `composer.json`, run `/opt/onedrop/restart`.
