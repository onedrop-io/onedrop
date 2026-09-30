# REQ

Everything OneDrop has to be, in one place: the requirements (the stories in `SPEC.md`, summed up by area) and the decisions made along the way, with the reasons for them. `SPEC.md` says _what_ users can do; this file also says _why it is that way_ and what was ruled out, so a later change doesn't undo a choice by accident. See `AGENTS.md` for when and how to update it.

## Product

- OneDrop is a self-hostable, Replit-style app builder: a person describes an app in chat, a coding agent builds it in a sandbox, and the running app is live at a URL while they work.
- Target users are trusted staff, not anonymous strangers. Real-time multi-user editing of one app is out of scope.
- Any stack, but Laravel's React starter kit by default (AGT-008).
- Bring your own AI: each user connects their own Claude, Codex/ChatGPT, OpenRouter or Gemini account. No markup on AI.
- Runs on a laptop (Docker), a single server, AWS, or Laravel Cloud from the same codebase.

### Decisions

- **2026-09-30: One codebase for self-hosting and for our multi-tenant hosted OneDrop.** Anyone can self-host one install; we also run one hosted install that serves many companies, so organizations must be a real boundary, not a kind of group. Groups stay as teams inside an organization.

- **2026-09-30: Name is OneDrop, never "zap".** `zap` is only the local checkout folder. Use `onedrop` in code, paths, images, headers; `APP_*` env vars on the platform server, `ONEDROP_*` inside sandboxes (where `APP_*` belongs to the user's app). Everything was renamed as a clean break, no migration for old sandboxes.
- **2026-09-28: Source available under the Elastic License 2.0.** Say "source available", never "open source".
- **2026-09-27: Inertia + React throughout; no Livewire,** in the platform and in the default app template.
- **2026-09-28: Postgres unless `DB_CONNECTION` says otherwise.** Local, tests, servers and the Docker install set `sqlite` explicitly; hosts like Laravel Cloud that only inject Postgres settings just work. Avoid SQL that only one of them supports.
- **2026-09-29: Dark theme by default.**
- **Build plan:** Laravel never holds a long-lived connection or supervises a process. Long-running work happens in the sandbox (agent, forwarder) or a queue job; Laravel coordinates, stores and broadcasts.

## Accounts, people and settings

AUTH-001..003, GRP-001..003, USR-001, INV-001, SET-001, BRAND-001.
Email/password (Fortify) plus Google, Microsoft, GitHub, GitLab and OIDC sign-in; two-factor and passkeys; groups with owners; admin user management; invite links; an account menu with settings in a modal.

### Decisions

- **Sign-up stays open to everyone;** invites are a convenience, not a gate (INV-001).
- **2026-09-29: The first account on a server install can only be made from the installer's one-time setup link** (`SETUP_TOKEN`), so a stranger who finds a new server first can't make themselves admin. On a local Docker install the first person to sign up is admin.
- **2026-09-30: Email verification is off by default when `APP_ENV=local`** (mail only goes to the log); on everywhere else. `AUTH_VERIFY_EMAIL` still wins.
- **Provider emails count as verified only when the provider says so;** Microsoft's never do. An unverified email is never auto-linked to an existing account.
- **2026-09-29: One GitHub App covers Tools → Git and "Continue with GitHub"** (`GITHUB_APP_CLIENT_ID/SECRET`); `GITHUB_CLIENT_*` override it for a separate OAuth app.
- **2026-09-28: Trust Laravel Cloud's load balancer** so OAuth callbacks come out as `https://` (the loopback-only `trustProxies()` had turned Cloud's handling off).
- **2026-09-28: Groups, Invite people and Users live in the settings modal,** not as top-level pages.

## AI connections and agents

AI-001..006, AGT-001..009, USAGE-001.
Users connect an AI before building; each project picks its agent (OpenCode, Claude Code or Codex), model and reasoning level; runs can be stopped, queued or sent now; prompts can be recalled; attachments work; usage and estimated cost are tracked per agent, model and project.

### Decisions

- **The platform never pools subscriptions.** Every run uses the project owner's own connection. Keys are encrypted and never sent back to the browser.
- **2026-09-29: Claude subscriptions use Claude Code's own sign-in** (`claude auth login` in the Shell tab). No token is stored; pasted setup tokens are refused and saved ones deleted. One sign-in covers all of a user's Docker sandboxes (per-user `CLAUDE_CONFIG_DIR`).
- **2026-09-30: A rejected Claude sign-in is retried once,** because Claude Code refreshing a shared login elsewhere revokes the token a run started with. Rejected twice, Claude Code is signed out in that sandbox so the chat offers to sign in.
- **2026-09-29: A run that failed because Claude Code wasn't signed in is remembered** and re-run once the user signs in.
- **2026-09-30: Codex is a third agent** next to OpenCode and Claude Code. Codex gets the ChatGPT tokens without the refresh token; the platform keeps refreshing it.
- **2026-09-30: AI onboarding is a picker, not a wall of forms.** The user picks one AI from a row of icons, then sees only that provider's setup. Subscriptions come first (Claude subscription, ChatGPT sign-in, OpenRouter sign-in); an API key is the small "instead" link. Gemini is key-only, since Google forbids subscription use. Settings → AI keeps the full list of cards, with Claude also subscription-first there, so both pages agree.
- **2026-09-30: Ollama connects with an Ollama Cloud key, or the user's own server by URL plus an optional Bearer key.** Cloud is the main option; it's models.dev's `ollama-cloud` provider, so OpenCode runs it as-is. The key is checked with `POST ollama.com/api/me`, because Ollama's model list answers without a key.
- **2026-09-30: A user's own Ollama server reuses OpenCode's `ollama-cloud` provider,** with its `baseURL` and the server's models (at zero cost) passed in `OPENCODE_CONFIG_CONTENT`. That keeps model ids, usage and the picker the same as Cloud, and the zero costs stop OpenCode pricing self-hosted runs at Cloud prices. The model list comes from the server's `/api/tags`, cached for an hour.
- **2026-09-30: The platform won't call private, loopback or internal Ollama addresses** (e.g. the cloud metadata address): the URL is user-typed, so this blocks server-side request forgery. The checked address is pinned for the request, and redirects aren't followed. `SANDBOX_OLLAMA_PRIVATE_SERVERS` allows them, and it's on by default when `APP_ENV=local`. There, Docker sandboxes reach a `localhost` server as `host.docker.internal`.
- **Agent CLIs are pinned in `docker/sandbox/Dockerfile`.** Bumping one means re-checking its events parser (`OpenCodeEvents`, `ClaudeCodeEvents`, `CodexEvents`) against the CLI's real output.
- **Usage costs are API-price estimates,** even for subscription runs (what the run would have cost on an API key).
- **Laravel by default (AGT-008):** asking for React, TypeScript, Tailwind or a landing page still gets the Laravel starter kit, which already uses them. Only a named framework, "no server", or an existing app changes it.

## Projects and the workspace

PRJ-001..008, LAYOUT-004, NOTIF-001, TAB-001, FILE-001..006, LIVE-001..002, TASK-001..004, ERR-001, DEVTOOLS-001.
Chat on the left, live preview on the right; resizable panels; files, shell and tools tabs; a sidebar with project menus, search and status; templates; icons; desktop notifications; parallel tasks with their own copy of the app and a kanban board; the agent sees and fixes the app's errors.

### Decisions

- **2026-09-28: Panel widths are remembered:** chat and files in `localStorage`, the sidebar in a cookie so the server renders it without a jump.
- **Inputs revealed by a click get focus automatically** (dialogs, inline forms).
- **2026-09-29: Each task gets its own copy of Main's sandbox,** forked at one instant for any stack (live rsync, then a second pass with processes frozen ~1s). Apply to Main / Update from Main merge through git bundles and hand stack-specific follow-up (deps, migrations, conflicts) to the agent.
- **2026-09-30: Live updates over Reverb, with polling as the fallback.** One "project changed" signal per request on a private per-project channel; a broadcasting failure never breaks the request or job.
- **2026-09-30: The preview reloads itself while the agent works, from the sandbox's proxy.** Users only saw changes when the chat finished, because the default Laravel stack builds with `vite build --watch` (only `$PORT` is reachable, so no Vite dev server and no hot reload) and the app builder reloaded the preview only on finish. The proxy's preview script listens on `/__onedrop/live` (server-sent events, which Caddy and the Cloudflare Worker stream) and reloads after `public/build/` is written or a `.php` file changes, once writes are quiet for 300ms. Not every file change: reloading before a rebuild finishes shows the old build, and apps with their own hot reload would reload twice. Not Vite's dev server through `routes.json`: its HMR socket address and asset origin differ per preview/published host, and published pages would serve dev assets. The watcher (inotify) only runs while a preview page is open.
- **2026-09-30: Every file and folder has its own "⋮" menu (also on right-click), next to the panel's header menu.** Actions that open a dialog, input or the Shell wait for the menu to close so they keep focus.
- **2026-09-30: A "Search files" box is always at the top of the files panel (in place of the "Files" title), filtering the tree by name in the browser;** a folder's "Search this folder" scopes the same box to that folder. No content search, because the tree already holds every path and a grep would be a new call into the sandbox.
- **2026-09-30: "Go to file" is Cmd/Ctrl+P, not Cmd+T,** because browsers never let a page take Cmd+T (new tab); Cmd+P (print) can be overridden and is what VS Code uses. It listens in the capture phase so it works inside the editor; it can't work while the Shell iframe has focus.
- **2026-09-30: Go to file matches in the browser over the tree's paths, with a small fuzzy scorer, no Orama or other search library.** The tree is at most 5,000 paths and already loaded; path matching wants in-order letters and word starts (`usctl` → `UserController`), which a full-text engine's word tokens and BM25 don't give. It re-lists the files when opened, since the tree isn't kept current while the files panel is closed. Hidden files are listed whatever the panel's hide setting, because typing a name like `.env` means you want it. Recent files are kept for the page's session only.
- **2026-09-30: "Open shell here" opens a new Shell tab at `?arg=cd&arg=<folder>`,** which `shell-entry` honours only for folders inside `/workspace`. A new tab, not the open Shell restarted, because a cross-origin terminal can't be typed into and restarting would end a session the user may still need. "Sign in to Claude" opens a new Shell for the same reason. Sandboxes on older images open in `/workspace` until they update.
- **2026-09-30: The chat can be hidden (LAYOUT-001), remembered in `localStorage` for every project,** like the files panel. It stays mounted while hidden so a draft isn't lost.
- **2026-09-30: The workspace splits into up to four panes, all side by side or all stacked (LAYOUT-002),** not a nested tree of splits, to keep it simple; "Split down" when panes are side by side restacks them all. A split opens a new Shell, since two Shells side by side (or the preview next to a Shell) is the main reason to split. Each "+" adds a new Shell every time (Shell 2, Shell 3…; a ttyd connection is its own bash).
- **2026-09-30: All panes are one CSS grid, and every tab's content is a child of it in a fixed order, placed in its pane's cell.** Moving an iframe in the page reloads it, so a tab moving between panes only changes its grid position: a Shell keeps its session and the preview doesn't reload. No separate component per pane for that reason.
- **2026-09-30: Closing a pane moves its tabs to the pane next to it rather than closing them,** so no Shell session is lost by accident; Tools and Preview can move between panes but never close. Tabs opened from outside a pane (files, Tests, "Open shell here") go to the pane last used; clicking into a preview or Shell counts, detected through the window's `blur`, since iframe clicks never reach the page.
- **2026-09-30: Panes under the chat or under the preview, and a layout remembered per project, are planned (LAYOUT-003),** not built yet. Layouts aren't saved for now because a reload restarts every Shell anyway.
- **2026-09-30: Rename and delete refuse `.` and empty path parts,** so neither can ever act on the workspace itself; renames never overwrite (like creating). "Copy link" is for files only, since the URL can open a file but not a folder.
- **2026-09-30: Each task has its own `read_at`; the project's dot rolls up its main chat and its not-done tasks.** Opening one chat only marks that chat read, so a task finishing while the user is on Main still shows. Done tasks don't count, since the sidebar doesn't list them.
- **2026-09-30: Reloads from an unfocused tab don't mark anything read** (the client sends `X-Onedrop-Unseen` on partial reloads when the tab is hidden or unfocused). Polling used to mark the open chat read in background tabs, so a finished agent never showed as waiting. Visits the user starts always count as seen; focusing the tab reloads the sidebar to clear the open chat's dot.
- **2026-09-30: The preview's size menu has three fixed widths (Desktop fills the pane, Tablet 768px, Mobile 390px), remembered in `localStorage` for every project.** It only narrows the iframe, so the app's own CSS media queries respond as on a real device; no device frames, rotation or custom sizes, to keep the header small. No user-agent or touch emulation, since a cross-origin iframe can't have either.
- **Browser errors are only accepted from the preview,** never from the published address. Autofix sends errors to the agent once; a run started by autofix doesn't trigger another.

## Sandboxes

SBX-001..007, TOOL-001, and every workspace tool (DB-001, SECRET-001, STORE-001, FLAG-001, MON-001, APPAUTH-001..002, GROW-001..002, RT-001).
One sandbox per project, on Docker locally and Blaxel (or Runtime Cloud) in production, kept up to date, checkpointed after every turn, backed up outside the provider, and suspended when idle.

### Decisions

- **One image definition** (`docker/sandbox/Dockerfile`) for every provider; nothing provider-specific in it except Blaxel's extra steps in `docker/sandbox/blaxel`.
- **No secrets in images or repos.** The user's AI credential is injected per run.
- **2026-09-28: Each sandbox is driven by the provider it was created on** (`RoutingSandboxProvider`); `SANDBOX_PROVIDER` only picks where new ones go. A project on another provider counts as outdated, so the normal update moves it.
- **2026-09-28: Updates keep all of `/home/sandbox`,** not just the agent's history, because agents install things there (a project's Postgres lived in `/home/sandbox/pgsql`).
- **2026-09-28: The old sandbox is deleted only after the new one has every kept file.** Any failure before that deletes the new one and puts the project back on the old one, unfrozen. (A queue timeout redelivering a long update job once lost a project's database.)
- **2026-09-28: Freezing for a copy always has a watchdog** that thaws the app after the longest an update may run, so a deploy that cuts an update off never leaves an app frozen.
- **2026-09-30: Old Runtime image versions are deleted daily (`sandbox:prune-images`),** because Runtime charges $0.08/GB-month for every stored image (~4.8 GB each) and every build leaves one behind; the account had 19. Kept: the current version (deleting it would also drop its build checkpoints), newer builds (a failed one holds the checkpoints its fix starts from), and any version a running, paused or stopped-persistent sandbox came from (read from its image label, `zap.image` for sandboxes made before the rename), since we don't know that a paused sandbox wakes without its image. Only versions of the configured image's name are touched, never other images on the account. It runs whenever `RUNTIME_API_KEY` is set, not only with `SANDBOX_PROVIDER=runtime`, because Runtime sandboxes outlive a provider switch.
- **2026-09-29: `sandbox:update` suspends each sandbox it updated,** to stay within Runtime's trial limit of 8 running sandboxes.
- **`RUNTIME_FUNDING=trial` until the owner says to use paid credit.**
- **Code backups (git bundles) live on a disk outside every sandbox provider,** so a lost sandbox can get its code back.
- **2026-09-29: Share cards, icons, attachments and backups use the app's default disk,** because on Laravel Cloud web and queue instances don't share a local disk.
- **Checkpoints never include `node_modules`, `vendor`, `.cache` or `.env`,** whatever the app's `.gitignore` says.
- **2026-09-28: The sandbox's host proxy rewrites `localhost` links** to the visitor's address, so apps that ignore `X-Forwarded-Host` work in previews without per-app config.
- **Proposed (docs/plans/devbox-sandboxes.md), not built:** Nix + Devbox for any stack; platform tools under `/opt/onedrop` called by absolute path so a project's stack can't break them.
- **2026-09-30: Docker inside sandboxes is one admin setting per provider** (Docker: off, privileged, runtime), not per project for now. Privileged is refused outside a local install. Each sandbox gets its own anonymous volume at `/var/lib/docker`, deleted with it and not carried across updates, because two sandboxes (old and new during an update) must never share one Docker data directory. Images re-download after an update; data in the project folder is kept.
- **2026-09-30: `.onedrop/dev` stays the start contract for compose projects.** `/opt/onedrop/compose init` writes it (`COMPOSE_FILE` plus `compose up --preview <port>`), and a small TCP forwarder takes `$PORT` to the stack's port, so the preview, host proxy and gateway don't change. `up` doesn't `--build`: compose builds only images it doesn't have, so a restart doesn't rebuild.
- **2026-09-30 (docs/plans/docker-compose-projects.md), started with SBX-008 (local Docker):** general for any repo, not one customer's. The platform detects how a repo runs (Compose file sets and profiles, devcontainer hints, its own Devbox setup) and the user picks. A project's own `docker-compose.yml` runs unmodified with Docker inside its sandbox on every provider (Sysbox or gVisor on servers, never `--privileged` outside local). No translating services to Nix, because Mongo and Elasticsearch are unfree there and an app's own Dockerfile can't be translated. No sibling containers on the host, because that only works with the Docker provider. Tested locally 2026-09-30: platform2's full stack ran this way on OrbStack. Docker's data must be on its own volume, because overlay on overlay fails.

## Previews, publishing and sharing

PUB-001..002, GW-001..002, REM-001, SHARE-001..002, HOME-002.
Every app is reachable while it's built; publish to Tailscale or to the server's own domain, Private or Public; share a project on a public page with a preview card and Remix.

### Decisions

- **Every path to a sandbox goes through the platform's auth check** (`SandboxGatewayController`). Never hand out a provider's own preview URL.
- **2026-09-29: On Laravel Cloud, previews and shells go through a Cloudflare Worker** on `*.<domain>`, because Blaxel's and Runtime's preview cookies don't work inside OneDrop's frame (Runtime didn't load at all; Blaxel lost CSS/JS in Safari). The browser only ever sees OneDrop addresses and cookies.
- **2026-09-29: Publish targets: Your domain or Tailscale,** remembered per project. Private on your domain means anyone signed in to OneDrop, not only the project's people. A project named like a preview address gets an `app-` prefix so it can't take one over.
- **2026-09-30: Tailscale publishing checks the node's capabilities (`https`, and `funnel` for Public) before running `tailscale serve`/`funnel`, and waits for them.** With Funnel off, `tailscale funnel` prints an enable link and blocks until someone turns it on; our timeout killed the job and left the project "Publishing…" forever while the command kept waiting in the container (so the URL later worked but the panel never updated). Now the panel links to the setting and publishing carries on once it's on. Both publishing jobs also mark the project Failed if they crash.
- **2026-09-29: Share cards are rendered in the sandbox** with headless Chromium (1200×630, prompt beside the app).

## Git

GIT-001..012.
History, commit/discard, branches, restore, push/pull, the GitHub App, commit and push from the header, pull requests, combining commits, hunk/line staging, undoing the last commit, asking the agent about a change, and readable diffs.

### Decisions

- **2026-09-29: Remotes are HTTPS only;** the local bundle steps (git's `file` transport) are exempt.
- **2026-09-30: Commit, push and Create PR live in a split button next to Share,** so the everyday steps don't need Tools → Git. With no remote it says "Commit" and its menu offers "Connect a repository…" (opening Tools → Git).
- **2026-09-30: There is one commit dialog,** "Commit changes", used by the header and by Tools → Git (which lost its inline message box), so reviewing and committing work the same everywhere.
- **2026-09-30: The commit message is optional; left blank, the project's own AI writes it** (the same one-off run as project naming), falling back to naming the files. Pull request text and combined-commit messages work the same way. The AI never blocks the action: every AI-written text has a plain fallback.
- **2026-09-30: "Default branch" means `main` or `master`,** a guess: the sandbox doesn't know the remote's real default. Pull requests go into it by default.
- **2026-09-30: Parts of files are picked at commit time; there's no lasting staging area.** The agent's checkpoint adds everything after every turn, so anything staged by hand would be swept into its next commit. Picking rebuilds the index from the last commit for each commit.
- **2026-09-30: The browser sends which lines to leave out plus a hash of the diff it showed, never patch text;** the sandbox rebuilds the patch and refuses ("This file changed. Review it again.") when the hash doesn't match, so a commit is always what the user saw. Only edited or new text files can be split; deleted, renamed and binary files go whole. Discarding a hunk is for edited files only.
- **2026-09-30: Sandboxes on an older git tool fall back quietly:** without line counts the Edit button is hidden (an old tool would commit every file), and without a diff hash parts can't be picked or discarded.
- **2026-09-30: Pull requests open GitHub's own new pull request page with the title and description in the link,** rather than being created through the API: it works for token and GitHub App remotes alike without asking for pull request permissions, and the user finishes on GitHub. Create PR says why it isn't available instead of hiding.
- **2026-09-30: Combining and undoing only touch commits no remote branch has,** so pushing never needs to overwrite (force-push) the remote. Combining refuses merges and uncommitted changes; undo refuses the first commit and merges, and checks the newest commit is still the one shown.
- **2026-09-30: "Ask" puts the hunk in the chat box and doesn't send it,** so the user writes the question; the dialog closes and the chat box keeps the focus.
- **2026-09-30: Diffs are colored with the CodeMirror grammars the chat already uses,** no new dependency. Changed words are found by comparing a removed line with the added line paired with it (common start and end, by whole words). Hiding whitespace is done in the browser, so picking and discarding wait until whitespace is shown again (the lines must match what the sandbox sees).

## Agent skills

SKILL-001..004. Reusable instructions for the agent in the open Agent Skills format (a folder with a `SKILL.md`). Two kinds: a user's own skills, kept by the app and turned on per project, and project skills committed in the repo.

### Decisions

- **2026-09-30: Both a library and project skills,** because a team wants the same skill across projects (library) and a skill that travels with one repo (project). Library skills live in the app's database, never in the repo.
- **2026-09-30: Sharing is all or nothing: private, or everyone on the server.** No per-group or per-person picker for now. Only the owner (or an admin) edits a shared skill. Stopping sharing turns it off in other people's projects, so nobody keeps running a skill they can't see anymore.
- **2026-09-30: Add menu: write one, create with agent, import from GitHub, upload.** Writing, importing and uploading add to the user's skills and turn it on in the current project. "Create with agent" writes a project skill in `.agents/skills` (following `guides/skills.md`), because the agent works on the repo; "Save to your skills" copies it into the library.
- **2026-09-30: The app puts skills in place before each run** (`SandboxSkills`, `docker/sandbox/skills.php`): library skills go to `~/.claude/skills` for Claude Code and `~/.agents/skills` for OpenCode and Codex. The bundle is only uploaded when it changed (the sandbox keeps a hash), so an unchanged run costs one exec. Only one of the two folders is filled at a time, because OpenCode reads both and would see every skill twice.
- **2026-09-30: Every agent sees every project skill.** Claude Code only reads `.claude/skills` and Codex only `.agents/skills`, so project skills from the other folders are copied into the agent's home skills folder. New project skills go in `.agents/skills`, the open standard's folder.
- **2026-09-30: The sandbox tracks what it installed** (`~/.onedrop/skills/installed.json`) and only removes those, so skills a user put in `~/.claude/skills` by hand are left alone, and win over one of ours with the same name.
- **2026-09-30: A project skill wins over a library skill with the same name,** and two library skills with one name can't both be on in a project. Agents need unique names.
- **2026-09-30: Skill problems never stop a run.** A missing or old `skills.php` (an older image) is skipped quietly, and the run goes on without skills.
- **2026-09-30: The Requirements switch (REQ-002) sits in a "Built-in" card at the top of Agent Skills,** because it changes how the agent works the way a skill does. It's a project setting, not a skill in the library.
- **2026-09-30: At most 50 files and 1 MB per skill,** stored in the database (files base64 in JSON), so no extra disk and skills come back with a database backup.
- **2026-09-30: Saving a project skill to the library skips symlinks,** so a shared skill can't pull in files from outside its folder.
- **2026-09-30: SKILL.md's frontmatter is read by a small parser,** not symfony/yaml, because that's only a dev dependency here. Editing a skill rewrites only `name` and `description` and keeps its other keys (license, allowed-tools, ...).

## Requirements tracking

REQ-001..002, TEST-001..003. The agent in a user's app keeps that app's requirements the way this repo keeps its own: what the user asked for ("User should be able to…" with IDs) and every decision behind it, with the reason, and backs each with browser tests it runs as it works. The user reads the requirements in a Requirements tab to check the agent understood, and watches the tests' recordings in a Tests tab; the agent reads them so it doesn't undo an earlier choice.

### Decisions

- **2026-09-30: One file, `.onedrop/REQ.md`, holding both the stories and the decisions.** This repo splits them into `SPEC.md` and `REQ.md`, but for a non-developer one page per area (its stories, then its decisions) is easier to read and review. It lives in `.onedrop/`, the platform's folder, so an app that has its own `REQ.md` or `SPEC.md` never gets it overwritten; it's still committed and backed up with the app.
- **2026-09-30: On by default for every project, existing ones included,** with a switch in the Requirements tab and in Tools → Agent Skills. Turning it off only stops the agent adding to it; the file stays.
- **2026-09-30: Delivered as extra instructions, not as a skill.** The forwarder appends `guides/requirements.md` to the agent's instructions when `APP_REQUIREMENTS=1` (a copy of `opencode.json` listing it, for OpenCode), so every run follows it. A skill is only loaded when the agent decides it's relevant, and this has to happen on every change. The switch is shown among the skills because that's where users look for how the agent behaves.
- **2026-09-30: The agent writes it in the same turn as the change, in the user's words,** and skips questions and changes that don't touch what the app does. For an app that already exists it only records requests from now on; it doesn't invent history.
- **2026-09-30: The tab reads Main's sandbox** (like Files); tasks write to their own copy and their additions arrive with Apply to Main.
- **2026-09-30: Playwright Test for every stack, not Pest's browser plugin for Laravel apps,** so there's one format to run, parse and record, with video and traces built in. Tests live in `tests/e2e` and are committed with the app.
- **2026-09-30: The platform's own Playwright config runs them** (`docker/sandbox/playwright.config.mjs`), not the app's: the preview's dev server as `baseURL`, one worker (the tests share the app's one database), a video and a trace of every test, and the image's Debian Chromium, so no browser download. Only Playwright's ffmpeg (for video) is needed; the image has the pinned version's (1.63.0, which the guide tells the agent to install), and an app on another version fetches its own once.
- **2026-09-30: Tests are linked to requirements by Playwright tags** (`{ tag: '@REQ-001' }`, or `@REQ-001` in the title). A test can check several; untagged ones are listed under "Not linked". The Requirements tab badges each `REQ-NNN` heading, and the Tests tab names each group from REQ.md.
- **2026-09-30: The agent runs the tests of what it touched before finishing (`/opt/onedrop/run-tests @REQ-003`), and the user can run all, a requirement's, or one from the tab.** Runs from the tab happen in the sandbox in the background (`tests.mjs run --background`) and the tab polls a state file, so Laravel never waits on a run. One run at a time.
- **2026-09-30: Only each test's latest result and recording is kept,** in `.onedrop/tests` (excluded from git by the checkpoint), each run in its own folder so running one test keeps the others' videos; folders no result points to are deleted. Videos are fetched whole into the browser so they can be scrubbed through.
- **2026-09-30: Tests are a required step of the requirements checklist, not a separate guide the agent may skip.** In the first version the agent kept REQ.md on every turn but wrote no tests for a small change ("+5 button"), and requirements from before tests existed never got any. Both guides now say a turn that changes the app isn't done until its requirements have passing tests, and to backfill tests for requirements without them. The Tests tab also lists requirements without tests with a "Write them with agent" button, like Feature Flags' agent actions.
- **2026-09-30: Watching and interacting with tests is Playwright's own UI mode, opened in its own window.** It already shows each step's DOM snapshot, timings, source, console and network, picks locators, and reruns on file changes (watch mode), so no custom live viewer. Its own window because there's more room, and because an iframe couldn't carry its cookie across hosts over local http. Not a headed browser over VNC: heavier (a display server per sandbox) for little more than UI mode's snapshots give.
- **2026-09-30: The runner goes through the preview's host proxy under `/__onedrop/tests-ui/`, not a new port,** so every provider and the server gateway work unchanged. It's guarded by a random token per runner, handed out by the app only to people who can change the project and traded for an HttpOnly cookie scoped to that path, and it's never served on a published address. Relying on the preview hostname alone wasn't enough: Blaxel and Runtime preview URLs are public, and the runner can run code. Its port is reserved so `routes.json` can't expose it.
- **2026-09-30: The runner stops after 30 minutes unused** (the proxy notes every request and open socket), so a forgotten window doesn't hold a Node process and browsers in the sandbox. Runs made in it don't update the Tests tab's results, which come from the tab's and the agent's runs.
- **2026-09-30: Taking over a test's page means a live browser streamed into the workspace, starting from a step of an existing test.** The user chose exact state (open dialogs, form input) over handing the page to the Preview with only its cookies and address, and steps of a test over scenarios written from chat. The stream is the debugging protocol's screencast, and input goes back the same way, so no display server.
- **2026-09-30: A taken-over test can carry on (TEST-006): one more step, or to its end,** by waking its worker (SIGCONT) after writing how far to go to a control file the preload reads on waking; it pauses again after the step, or at its end before the teardown that would close the page. Resumed steps wait 500 ms each (the worker blocks with `Atomics.wait`, the browser carries on), since steps run too fast to follow otherwise; not Playwright's `slowMo`, which would also slow the run up to the step and can't change mid-run. A failing step is reported with Playwright's own message, and the page stays open.
- **2026-09-30: Tests run in the Browser tab have no timeout.** Their timeout's clock runs on wall time, so a test paused for longer than 30 seconds would time out the moment it resumed.
- **2026-09-30: A test stops at its step by freezing its worker (SIGSTOP) from inside it,** in a preload (`test-steps.mjs`) that watches the step reports a worker sends just before each step. That's the only point before the next action reaches the browser; patching Playwright's internals isn't possible in 1.63 (it's one bundle), and stopping from the runner's reporter would be too late. The browser is its own process and keeps the page. The same preload records every test's steps during normal runs, for the step list.
- **2026-09-30: The Browser tab is a viewer served from the sandbox under `/__onedrop/browser/`,** in an iframe with its token in the address (every request and its socket are checked), because a cookie from the preview's address isn't sent inside the workspace's iframe. Never on a published address. It was going to be `/__onedrop/live/`, but that path belongs to live reload (LIVE-002).
- **2026-09-30: Each take-over's browser gets the first free debugging port of 9224–9233,** all reserved from `routes.json`, so one left over from a crashed session is never the one attached to. One take-over at a time per sandbox; a new one replaces it.
- **2026-09-30: While a take-over is open the agent's prompt says which test and step it came from, and how to look** (`/opt/onedrop/browser screenshot`). The note is kept in the cache for an hour (the browser closes itself after 30 minutes unwatched), so no migration.
- **2026-09-30: The requirements switch covers tests too.** Tests exist to back the requirements, so one switch; turning it off saves the time a turn spends writing and running tests.

## Install, deploy and admin

INSTALL-001..002, DEP-001, ADMIN-001..005.
One-line install on a computer or a server; AWS via Pulumi; Laravel Cloud; admin settings for name and logo, sandbox providers, server monitoring, domain/HTTPS and database backups.

### Decisions

- **2026-09-29: The Docker install is one FrankenPHP container** (app and queue worker) with SQLite in a volume; projects run in sibling sandbox containers through the Docker socket.
- **2026-09-29: A server install only publishes 80/443;** previews and shells are `preview-<id>.<domain>` / `shell-<id>.<domain>` behind the gateway's sign-in check.
- **2026-09-30: Admin settings saved in the app win over `.env`,** and queue workers pick them up without a restart.
- **2026-09-30: Sandbox providers are an ordered list with on/off switches, not one "active" pick.** New projects run on the first one that's on and has its required settings; an admin drags to reorder, and selects one to see its settings in a pane beside the list. The order is the place to later send a project that needs something (e.g. Docker inside the sandbox for Compose) to the first provider that supports it. Installs that saved an active provider before there was an order keep it first. No failing over to the next provider when one errors, for now.
- **2026-09-30: The install command is `curl -fsSL https://onedrop.io/install | sh`,** a redirect to `install.sh` on main's raw GitHub URL. The raw URL was too long to fit on the home page without a horizontal scroller. A redirect, not serving the file, so the script stays whatever is on main (not the deployed app's copy) and GitHub stays the source people can read; the raw URL still works and the install guide shows it. Every install has the route, which is harmless. The home page's command box wraps rather than scrolls on narrow screens.
- **Images are `ghcr.io/onedrop-io/onedrop` and `onedrop-sandbox`,** published for amd64 and arm64 once main's tests pass. The `drop` command, container, volume and network keep their short names.

## Marketing

HOME-001, HOME-003, PRICE-001.

### Decisions

- **Pricing:** self-hosted is free with no limits; paid plans are flat (not per seat), never mark up the user's own AI, never use credits for hosting, and published apps never pause. Yearly is the default and saves 20%.
- **2026-09-30: The demo video plays in a dialog from a Watch the demo button in the hero, streamed from Cloudflare R2** (`DEMO_VIDEO_URL` in `resources/js/lib/links.ts`). A dialog keeps the hero's animated demo in place, and hosting the MP4 off-repo keeps a large binary out of git and off the app server.
- **2026-09-30: The home page has its own Install section, with tabs for a laptop, a server (`--domain auto`) and an own domain, plus the GitHub logo in the top bar and footer.** Like the best source-available projects, self-hosters should find the command, what it needs and what happens next without opening the docs. The comparison section links to it instead of repeating the command, and "Nothing to install" was dropped from How it works because it contradicted the section. The GitHub mark is an inline SVG, not a lucide icon, because lucide is dropping brand icons.
- **2026-09-30: Marketing copy names both subscriptions, Claude (Pro or Max) and ChatGPT (Plus or Pro), wherever it says which AI you can bring, and lists Ollama with the API-key providers.** Earlier copy only named ChatGPT and often left out Ollama, which undersold what you can connect.
- **Third-party imagery on the home page is credited** in the footer, the README and `CREDITS.md`.
- **2026-09-30: Droppy (HOME-003), a Clippy-style droplet, hints at the home page's easter eggs.** The animation hides so much (close-ups on hover, zoom levels, a moonwalk inside the Earth close-up) that most visitors never find it. Found eggs and "Don't show Droppy again" live in `localStorage`, not the server: visitors are logged out, and it's only fun. Only eggs the page can detect (a close-up playing, a zoom level reached, the logo popping) count as found; the Endurance and Enterprise just play, so Droppy only hints at them. It waits for the galaxy (no galaxy, nothing to find) and hides with reduced motion and below the `xl` breakpoint, like the zoom scale. No docs page: it would spoil the eggs, and the home page isn't in the docs.
