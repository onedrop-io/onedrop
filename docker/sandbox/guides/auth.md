# Adding sign-in to the app

Follow this when the user asks to add or change how people sign in to their app. The app builder's
Tools → Users & Auth panel reads what you build here, so stick to the contract below.

## Principles

- Build sign-in into the app itself, with its stack's standard, well-maintained auth library. No hosted
  auth services (Clerk, Auth0, Firebase, Supabase) unless the user asks for one.
- Users live in the app's own database, in one table (normally `users`). Use the database the app
  already has; if it has none, add SQLite. Its connection must be in the env file (`DATABASE_URL`, or
  Laravel's `DB_*` settings), not only as a default in code, or the panel can't find the users.
- If the app has no server (e.g. a React + Vite front end), sign-in needs one. Say so in one sentence,
  then add a small server in the same language and serve both from the one dev server.
- Keep what already works. Don't restyle or restructure pages you don't have to touch.

## Recommended libraries

- Laravel: Fortify for email and password (or the Laravel starter kit's auth if it's already there),
  Socialite for Google, GitHub and Microsoft (Microsoft via the `socialiteproviders/microsoft` package).
- Next.js / Node / Express / Hono / SvelteKit / Nuxt: Better Auth (or Auth.js if it's already installed).
- Django: django-allauth. Rails: Rails' built-in authentication generator plus OmniAuth.
- Anything else: the framework's official or most widely used auth library.

## What to build

1. **Pages:** a sign-in page (at `/login` unless the framework has another convention), a sign-up page,
   and a sign-out action. Show the signed-in person's name and a sign-out button in the app's header.
2. **Email and password** (method `password`): hash with bcrypt or argon2, emails unique and
   case-insensitive, minimum 8-character passwords, clear error messages, rate-limit sign-in attempts.
   Only add password reset or email verification if the app can already send email.
3. **Google, GitHub, Microsoft** (methods `google`, `github`, `microsoft`):
   - Read keys from these env vars (the panel writes them into the app's env file; never hard-code them):
     `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`,
     `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`. Microsoft uses the `common` tenant.
   - Start at `/auth/{provider}/redirect`; the callback is `/auth/{provider}/callback`.
   - Build the callback URL from the incoming request, trusting `X-Forwarded-Host` and
     `X-Forwarded-Proto` (the preview and published addresses are proxied; the app itself sees
     `localhost`). In Laravel, trust all proxies (`$middleware->trustProxies(at: '*')`).
   - Only show a provider's button when both of its env vars are set, so the sign-in page never breaks.
   - Store the provider's user id (a `provider`/`provider_id` pair, or a separate accounts table). If a
     verified email matches an existing user, sign them in to that user instead of making a duplicate.
     Accounts from Google, GitHub or Microsoft don't need a password.
   - **OneDrop accounts** (method `onedrop`): the app builder itself is the provider, so people sign in
     with their OneDrop login. It's plain OAuth 2.0 (authorization code, PKCE with S256) plus a user-info
     endpoint. The app builder writes every setting into the env file itself: `ONEDROP_CLIENT_ID`,
     `ONEDROP_CLIENT_SECRET`, `ONEDROP_AUTHORIZE_URL`, `ONEDROP_TOKEN_URL`, `ONEDROP_USERINFO_URL`.
     Configure the three URLs explicitly and don't use discovery: the authorize URL is for the browser,
     while the token and user-info URLs are for the app's server and may be on a different host. Scope
     `openid profile email`; user info returns `sub`, `name`, `email`, `email_verified` and `groups`. The
     button says "Sign in with OneDrop". Laravel: a Socialite provider (extend `AbstractProvider`). Better
     Auth: the `genericOAuth` plugin with `authorizationUrl`, `tokenUrl` and `userInfoUrl`.
4. **Users table columns:** `id`, `name`, `email`, `role`, `created_at`, and `last_login_at`, updated on
   every successful sign-in by any method. Add missing columns with a migration if the table exists
   already. Also add the two account-control columns the panel sets (see "Account controls" below):
   `disabled_at` (nullable timestamp) and `password_change_required` (boolean, default false).
5. **Which pages need sign-in:** protect pages that show or change a person's own data, and make each
   person see only their own data. Leave landing and marketing pages public. Say what you protected.
6. **Safety:** sessions in HttpOnly, SameSite=Lax cookies, CSRF protection on forms, no passwords or
   keys in logs. Make sure the env file is in `.gitignore`.
7. **Tests:** add tests for signing up, signing in, a wrong password and signing out, following the
   project's test setup. Then check `curl -sf "localhost:$PORT/login"` works and read
   /tmp/zap-server.log if it doesn't.

## Account controls

The panel turns accounts off and asks people for a new password by setting columns in the users
table. The app enforces them **on every request**, not only at sign-in, so they apply to people who
are already signed in:

- `disabled_at` is set: refuse sign-in by any method with "This account has been turned off.", and end
  any existing session (sign them out and send them to the sign-in page).
- `password_change_required` is true: after sign-in, send them to a "Choose a new password" page (new
  password twice, at least 8 characters) and let them go nowhere else except signing out. Saving a new
  password there sets it back to false. People who signed up with Google, GitHub or Microsoft and have
  no password yet choose one there too.

Test both, including a user who was already signed in when the column changed.

### Roles

`role` is a text column. List the app's roles in auth.json's `roles`, most powerful first; use
`["admin", "member"]` unless the app needs others. New users get the last role (e.g. `member`), except
the very first user, who gets the first (`admin`). Admins can see and manage everything in the app;
members only their own data, unless the user asked for something else. Read the role from the database
on every request (the panel can change it at any time), and test that a member can't reach admin pages.

## Let the panel add users, set passwords and sign people out: /workspace/.zap/users

The panel edits names and emails and deletes users directly in the database, but creating a user or
setting a password needs the app's own auth library (so the password is hashed the way sign-in expects).
Add an executable script `/workspace/.zap/users` that:

- reads one JSON request from stdin and prints one JSON line to stdout (other output is ignored, so
  framework banners are fine);
- handles `{"op": "create", "name": "...", "email": "...", "password": "..."}` by creating the user
  exactly like sign-up does, replying `{"ok": true, "id": <new user id>}`;
- handles `{"op": "set-password", "id": "...", "password": "..."}` by replacing that user's password
  (for users from Google/GitHub/Microsoft, add a password so they can also sign in with email);
- handles `{"op": "sign-out", "id": "..."}` by ending all of that user's sessions (delete their session
  rows, or bump a session version the app checks), replying `{"ok": true}`;
- handles `{"op": "sign-in-link", "id": "..."}` so the app's owner can see the app as that user: make a
  random single-use token (32+ bytes), store only its hash with the user id and a 60-second expiry, and
  reply `{"ok": true, "path": "/auth/zap-sign-in?token=..."}`. That route consumes the token, starts a
  new session as the user (without updating `last_login_at`), and every page then shows a banner "You're
  signed in as NAME to check what they see · Stop", where Stop signs out. Refuse expired, used or unknown
  tokens with a plain message. List `"sign-in-link"` in auth.json's `helper`;
- replies `{"ok": false, "error": "..."}` with a plain-language message when something is wrong
  (e.g. the email is already used);
- runs from /workspace with the app's env file loaded, and finishes within a few seconds.

Examples: in Laravel, `#!/usr/bin/env bash` then `exec php artisan zap:users` with an artisan command
that uses `Hash::make`; in Node with Better Auth, a script that calls `auth.api.signUpEmail` and Better
Auth's password hasher (`ctx.password.hash`) to update the credential account, and deletes the user's
rows from its `session` table to sign them out. Test every operation.

## Describe it in /workspace/.zap/auth.json

Write this file when you're done, and update it whenever sign-in changes:

```json
{
    "version": 1,
    "library": "Laravel Fortify + Socialite",
    "login_path": "/login",
    "callback_path": "/auth/{provider}/callback",
    "methods": ["password", "google"],
    "env_file": ".env",
    "roles": ["admin", "member"],
    "helper": ["sign-in-link"],
    "users": {
        "table": "users",
        "id": "id",
        "name": "name",
        "email": "email",
        "role": "role",
        "created_at": "created_at",
        "last_login_at": "last_login_at",
        "disabled_at": "disabled_at",
        "password_change_required": "password_change_required"
    }
}
```

- `methods`: the methods the app offers, from `password`, `google`, `github`, `microsoft`, `onedrop`.
- `roles`: the app's roles, most powerful first.
- `helper`: the optional helper operations yours supports beyond create, set-password and sign-out.
- `env_file`: the file (relative to /workspace) the app reads the provider env vars from.
- `users`: the table and column names, if they differ from the defaults shown.

## Turning a method off

Remove its button and routes and update `methods`. Keep existing users and their data.

## When you're done

Tell the user in plain language where to sign in. If they chose Google, GitHub or Microsoft, tell them
to add that provider's keys under **Tools → Users & Auth → Configure**, where they'll also find the
callback URLs to register with the provider.
