# Hosting

Follow this when the app is (or will be) published to Hosting, and whenever you set up its database, cache or file
storage: written this way, the same code runs in the preview and hosted.

Published to Hosting, the app runs on its own, off this sandbox: a front end is served from Cloudflare; an app with a
server runs on a Fly.io machine with this sandbox's tools, a copy of /workspace and its own data. Deploying again
ships the code only; the hosted data stays where it is.

## .onedrop/host.json

Describe how the app is hosted in /workspace/.onedrop/host.json. Every field is optional:

```json
{
    "static": "dist",
    "services": ["postgres", "redis", "s3"],
    "data": ["database/database.sqlite", "uploads"]
}
```

- `static`: only for a front end with no server of its own (a Vite/React/Vue app that only calls other APIs). The
  folder, under /workspace, its build writes (`dist` for Vite). It's served as a single-page app: unknown paths get
  `index.html`. Leave it out for anything with a server (Laravel, Express, Next.js, Rails, Django, …). Without
  host.json, a plain Vite app (build script, index.html, no server) is taken for a front end; write host.json anyway,
  so it's never a guess.
- `services`: the managed services the app reads from its environment. Hosted, each gets its own:
    - `postgres`: `DATABASE_URL` (and, for Laravel, `DB_CONNECTION=pgsql` and `DB_URL`), a Neon database.
    - `redis`: `REDIS_URL`, an Upstash database.
    - `s3`: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION=auto`, `AWS_BUCKET`, `AWS_ENDPOINT` (also
      `AWS_ENDPOINT_URL_S3`), `AWS_USE_PATH_STYLE_ENDPOINT=true`, an R2 bucket. Only for apps that use the S3 API
      themselves; App Storage (guides/storage.md) needs nothing here.
- `data`: files and folders under /workspace the app keeps data in, other than the services above (a SQLite file,
  an uploads folder). They're kept on a volume when hosted, never replaced by a deploy. `.onedrop/data`,
  `database/database.sqlite` and App Storage are always kept, so list only others.

## Read services from the environment

- Connect to Postgres, Redis and S3 only through the variables above, read from the environment. In the sandbox, set
  them in the app's `.env` to the local services you run from .onedrop/dev (data in /workspace/.onedrop/data):
  hosted, real environment variables are set and win over `.env` (Laravel and dotenv never replace a variable that's
  already set).
- Never hard-code a database host, file path or bucket in code.
- `ONEDROP_HOSTED=1` is set when hosted, if the app ever needs to know.
- Don't add a service the app doesn't use: each is made at a provider on the first deploy.

## Starting it hosted

- `.onedrop/build` (executable, optional) runs in the sandbox before packing: build assets for production, e.g.
  `npm run build`. A front end must have one that writes its `static` folder. Keep it idempotent: it runs on every
  deploy, in this sandbox.
- `.onedrop/start` (executable, optional) starts the app hosted, on 0.0.0.0:$PORT like .onedrop/dev. Without it,
  .onedrop/dev is used. Write one when the dev server isn't fit for visitors (watchers, debug mode, Vite's dev
  server), and run what the app needs on every start first, e.g. migrations:

    ```bash
    #!/usr/bin/env bash
    set -e
    php artisan migrate --force
    exec php artisan serve --host 0.0.0.0 --port "$PORT"
    ```

- The hosted database starts as a copy of the sandbox's on the first deploy (Postgres, and files kept on the volume),
  then lives on its own. Migrations must work on it as it is: never reset or seed it from .onedrop/start.
- Check it the way it will run: `ONEDROP_HOSTED=1 .onedrop/start` must start the app with the build from
  .onedrop/build. Stop it again afterwards; the preview keeps using .onedrop/dev.

## Moving to Postgres

When the user asks to move a hosted app from SQLite to Postgres (Move to Postgres in the Publish panel, so the app can
scale), switch it over so the next deploy can copy its hosted SQLite data into a new Postgres:

1. Keep the sandbox on SQLite: leave `.env` and the SQLite file as they are. Hosted, the app gets `DATABASE_URL` (and
   `DB_CONNECTION=pgsql`, `DB_URL`) for its new Postgres, which win over `.env`.
2. Make the code work on both: no SQLite-only SQL in raw queries or migrations (`strftime`, `ifnull`, `group_concat`,
   `INSERT OR REPLACE`, `AUTOINCREMENT`…); use the query builder's portable methods instead.
3. Create an executable `/workspace/.onedrop/migrate` that makes the app's tables in whatever database the environment
   points at, e.g. `exec php artisan migrate --force` for Laravel, and run it from `.onedrop/start` too. Hosted, it
   makes the tables in Postgres before the SQLite data is copied in; every table and column in the SQLite file must
   exist after it, or nothing is copied.
4. Add `"postgres"` to `services` in `.onedrop/host.json`. Keep the SQLite file in `data` (Laravel's
   `database/database.sqlite` always is): the copy reads it from the volume.
5. If `docker info` works, check the migrations on a real Postgres: `docker run -d --name pg-check -e
POSTGRES_PASSWORD=check -p 5433:5432 postgres:16-alpine`, then (once it's up) `DB_CONNECTION=pgsql
DB_URL=postgresql://postgres:check@127.0.0.1:5433/postgres php artisan migrate:fresh --force`, and remove the
   container. Without Docker, say so: the first run on Postgres is the deploy, which puts the SQLite version back if
   the migrations or the copy fail.
6. Tell the user to click Update in the Publish panel. The deploy makes the Postgres, copies the hosted data in once
   and keeps the SQLite file on the volume.
