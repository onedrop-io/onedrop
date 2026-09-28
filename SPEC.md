# SPEC

What users should be able to do. Each entry has an ID; tests reference it with `->group('ID')`. See `AGENTS.md` for format.

## AUTH-001: Log in as the dev user

- User should be able to log in locally with `dev@example.com` / `password` after `php artisan migrate:fresh --seed`.
- The dev user should be a site admin.
- Dev seed data should not be created outside local/testing.

## AUTH-002: Accounts (starter kit)

- User should be able to register, log in, and log out.
- User should be able to reset a forgotten password.
- User should be able to verify their email address.
- User should be able to update their name, email, and password, and delete their account.
- User should be able to set up two-factor authentication and passkeys.

## AUTH-003: Log in with Google, Microsoft, GitHub, GitLab, or single sign-on (OIDC)

- User should see a "Continue with …" button on the log-in and sign-up pages for each provider the server has credentials for, and none when no provider is set up.
- User should be able to sign up with a provider when registration is open; the account has no password and uses the provider's name and email.
- User's email should count as verified only when the provider says it is (Google, GitHub, GitLab, OIDC `email_verified`); Microsoft emails always need verifying.
- User should be able to log in again with a provider they've used before.
- User should see their provider profile picture as their avatar after signing in or connecting a provider; it stays in sync with that provider and isn't replaced by providers connected later.
- User who already has an account should be linked automatically when the provider's verified email matches it; if the email isn't verified, they should see an error telling them to log in and connect the provider from settings.
- User with two-factor authentication on should still be asked for a code after signing in with a provider.
- User signing up with a provider from an invite link should accept that invite.
- User should see an error when the provider sign-in is cancelled or fails.
- Provider callback URLs should use the site's real `https://` address behind a trusted proxy (a local proxy, or Laravel Cloud's load balancer).
- User should be able to connect and disconnect providers on the security settings page, but not disconnect their last way to log in.
- User without a password should be able to open security settings without confirming a password, set a password, and delete their account without one.

## GRP-001: Create and browse groups

- User should be able to create a group with a name and optional description.
- User should become the owner of a group they create.
- User should see only the groups they belong to; admins see every group.
- User should not be able to view a group they don't belong to.

## GRP-002: Manage group members

- Group owner should be able to add an existing user by email as a member or owner.
- Group owner should see an error when the email doesn't match a user or the user is already a member.
- Group owner should be able to change a member's role and remove members.
- Member should be able to leave a group.
- A group should always keep at least one owner.
- Members who aren't owners should not be able to add, remove, or change roles.

## GRP-003: Edit and delete groups

- Group owner should be able to rename a group and change its description.
- Group owner should be able to delete a group.
- Members who aren't owners should not be able to edit or delete it.
- Admins should be able to manage any group.

## USR-001: Manage users (admin)

- Admin should be able to see a list of all users with their group counts.
- Admin should be able to grant or revoke admin access for other users.
- Admin should not be able to change their own admin access.
- Non-admins should not be able to see the users list.

## AI-001: Set up AI after sign-up

- After signing up, user should be asked to connect an AI before they can start building.
- User should be able to connect Claude with an Anthropic API key, with a small link to use a Claude Code token (`claude setup-token`) instead.
- User should be able to copy the `claude setup-token` command with one click.
- User should be able to connect Codex by signing in with ChatGPT (AI-003) or with an OpenAI API key.
- User should be able to connect OpenRouter by signing in to OpenRouter, with a small link to paste an API key instead.
- User should see an error when a key is rejected by the provider.
- User should be taken straight to the new-project prompt after connecting their first AI during onboarding.
- User should be able to continue to the app once at least one AI is connected.

## AI-002: Manage AI connections

- User should be able to see their connected AIs in settings, showing only the last few characters of each key.
- User should be able to replace a connection's key, choose the default AI, and disconnect an AI.
- Keys should be stored encrypted and never sent back to the browser.

## AI-003: Sign in with ChatGPT

- User should be able to connect Codex by signing in with their ChatGPT account (Plus/Pro), with a small link to paste an OpenAI API key instead.
- User should see a one-time code and a link to OpenAI's sign-in page, and be connected automatically once they approve it.
- User should be able to copy the code with one click.
- User should see an error when sign-in is denied, expires, or OpenAI can't be reached.
- User should be taken straight to the new-project prompt after signing in during onboarding.
- The agent should run on the user's ChatGPT subscription; the platform keeps the refresh token and refreshes it, and only a short-lived access token enters the sandbox.
- User should be told to sign in again when their ChatGPT sign-in can no longer be refreshed.
- User should only be offered models OpenAI includes with Codex when signed in with ChatGPT, and projects should start on one of those.

## PRJ-001: Start a new project

- After logging in, user should see a "what are we working on today?" prompt with suggestions.
- User should be able to describe an app and submit it to create a project.
- User should be able to pick a suggestion to fill in the prompt.
- User should be taken to the project workspace after creating it.
- The project should be named from the description.

## PRJ-002: Project workspace

- User should see the chat on the left and the app preview on the right.
- User should be able to drag the divider between the chat and the workspace, the files panel's edge, and the left sidebar's edge to resize them (arrow keys work too; double-click resets). The widths are remembered in their browser.
- User should see the agent's replies appear while it works, without reloading.
- User should be able to send follow-up messages to the agent.
- User should see the live app in the preview once it has a preview URL, and a placeholder until then.
- User should see their recent projects in the sidebar.
- User should not be able to see or message another user's project (admins can see all).

## PRJ-003: Project menu in the sidebar

- User should be able to open a "⋮" menu on any project in the sidebar, and press the letter shown next to an action to run it while the menu is open.
- User should be able to pin and unpin a project (P); pinned projects are listed under "Pinned" above "Recent".
- User should see a project as unread (dot, bold name) when the agent has replied since they last opened it, and be able to mark it unread or read (U). Marking the open project unread takes them to the new-project page.
- User should be able to rename a project (R) in a dialog with the name focused; an empty name shows an error.
- User should be able to regenerate a project's title: the project's AI reads the chat and suggests a short title, in the background, with a spinner in the sidebar until it's ready. If the AI can't be asked (sandbox not running, no usable AI), the name stays as it was.
- User should be able to share a project: see and copy the published app's link, or be told to publish it first.
- User should be able to copy the project's link (C).
- User should be able to archive a project (A), which moves it to a collapsed "Archived" section, and unarchive it from there.
- User should be able to delete a project (D) after confirming; this takes the app offline and removes its chat, attachments, and sandbox. Deleting the open project takes them to the new-project page.
- Renaming, regenerating the title, pinning, marking, and archiving should not change a project's place in "Recent".
- Admins opening someone's project should not mark it read for its owner.
- User should not be able to change or delete someone else's project.

## SBX-001: A sandbox per project

- Each new project should get its own sandbox, started automatically.
- User should see the sandbox starting, then the live app in the preview once it's running.
- User should see an error in the preview if the sandbox fails to start.
- Sandboxes should run in local Docker in development and on a managed provider (Blaxel or Runtime Cloud) in production, chosen by config.
- The user's default AI credential should be injected into the sandbox as an environment variable, not stored in the image.
- User should see the app's styles and scripts in the preview and at its published address even when the app writes its own links as `localhost` (e.g. a Laravel app that doesn't trust proxies); the sandbox's proxy points those links, redirects included, at the address the visitor used.

## SBX-002: Sandboxes stay up to date
- When the sandbox image is rebuilt (new guides, tools or proxy), existing sandboxes should move to it without anyone running a command.
- Opening a project whose sandbox is older than the image should update it in the background, unless the agent is working.
- Before the agent starts a run, an outdated sandbox should be updated first, so the agent always has the current guides and tools.
- Updating keeps the app's files, App Storage, the agent's history, and everything in the sandbox user's home folder (such as a database or tools the agent installed there); the app restarts, and a published project is published again.
- Updated sandboxes should get the image's current shell setup (banner, prompt, aliases, prompt theme) even though the home folder is kept; lines the user added to `~/.bashrc` below the loader line, and a `~/.config/starship.toml` the user made, stay.
- User should see the sandbox updating in the preview, then the app again.
- Two updates of the same sandbox should never run at once.
- An update that fails or is cut off partway (a deploy, a queue timeout) should leave the project on its old sandbox with every file; the old sandbox is only removed once the new one has them all.
- An admin should be able to update outdated sandboxes with `php artisan sandbox:update` (all, or one project).

## SBX-003: Sandboxes on Runtime Cloud
- With `SANDBOX_PROVIDER=runtime`, each project's sandbox should run on Runtime Cloud, from the sandbox image built there with `php artisan sandbox:build-image`.
- The sandbox's settings and the user's AI credential should reach it at start without appearing in any command line.
- User should see the preview and the Shell tab through private Runtime preview links, handed out only to people allowed to see the project; the links should be renewed before their tokens expire.
- Pausing, resuming, updating (files kept) and deleting a sandbox should work as they do with Docker.
- A sandbox made from an older image should be reported as outdated, so SBX-002 updates it.
- Sandboxes should use the free trial unless `RUNTIME_FUNDING=paid` is set; Runtime errors should reach the user with Runtime's hint.

## SBX-004: Sandboxes on Blaxel
- With `SANDBOX_PROVIDER=blaxel`, each project's sandbox should run on Blaxel, from the sandbox image pushed there with `php artisan sandbox:build-image` (docker/sandbox plus Blaxel's sandbox API).
- The user's AI credential should reach the sandbox as a secret setting, never in a command line.
- User should see the preview and the Shell tab through private Blaxel preview links, handed out only to people allowed to see the project; the links should be renewed before their tokens expire.
- An agent run should keep its sandbox awake until it ends, even with nobody watching; idle sandboxes should go to standby by themselves.
- Updating (files kept) and deleting a sandbox should work as they do with Docker, and a sandbox made from an older image build should be reported as outdated.
- While an update copies a Blaxel sandbox's files, the app's processes should be frozen, so a database the agent set up is copied in a consistent state; if copying fails, or the update is cut off (e.g. by a deploy), they should carry on where they were.
- Blaxel's errors (such as account limits) should reach the user in Blaxel's words.

## AGT-001: Real coding agent

- When user sends a message, OpenCode should run inside the project's sandbox using the user's default AI (Anthropic key, OpenAI key, or OpenRouter).
- User should see the agent thinking, then each step as it happens (reading, editing files, running commands) and its replies.
- The agent should remember the conversation across messages in the same project.
- Only one agent run should happen at a time per project; messages sent while it works are queued (see AGT-003).
- The preview should switch from the placeholder to the app's dev server once the agent sets one up, and restart when the agent asks.
- User should see a clear message if the agent fails or their AI can't be used (e.g. Codex, not supported yet).
- User should see a plain explanation with a link to add credits when their AI account's balance is too low.
- Agent events should only be accepted from the project's own sandbox (per-sandbox secret token).

## FILE-001: Browse project files

- User should see the project's files in a panel on the right of the workspace, as a folder tree.
- User should see a colored icon for each file and folder that shows its type (JavaScript, JSON, images, config, etc.).
- User should be able to open a file and read its contents in a tab next to the preview.
## SBX-005: Switch sandbox providers
- An admin should be able to switch where new sandboxes run by changing `SANDBOX_PROVIDER`, without breaking existing projects: each sandbox keeps being driven by the provider it was created on.
- Sandboxes on several providers should work side by side.
- A project whose sandbox is on another provider than the configured one should count as outdated, so it moves to the configured provider the way SBX-002 updates do (files, App Storage and home folder kept; the old sandbox removed only once the new one has them).
- An admin should be able to move every project now with `php artisan sandbox:update`, or one with `php artisan sandbox:update {project}`.
- Provider-specific behavior (renewing private preview links, the SSH notice) should follow each sandbox's own provider.

- User should see new files appear while the agent works, and be able to refresh the list.
- User should be able to hide and show the files panel; it starts open only on wide screens.
- User's choice to hide or show the files panel should be remembered in their browser across reloads.
- Large folders (node_modules, vendor, .git) should be listed but not expanded.
- Binary and very large files should show a notice instead of garbled or partial content.
- Only the project's owner (or an admin) should be able to read its files, and only inside the project's workspace.

## FILE-002: Edit project files

- User should be able to edit an open file in an editor with syntax highlighting for its language.
- User should be able to save with a Save button or Cmd/Ctrl+S, which writes the file into the sandbox.
- User should see when a file has unsaved changes, and be asked before discarding them.
- Unsaved edits should not be overwritten when the agent changes the same file; saved files should pick up the agent's changes.
- Saving should keep the file's permissions (e.g. executable scripts stay executable).
- Only the project's owner (or an admin) should be able to save files, and only inside the project's workspace.

## FILE-003: Files panel menu

- User should be able to open a "⋮" menu in the files panel header.
- User should be able to create a new file (by name or path, e.g. `src/utils.ts`) and have it open in the editor.
- User should be able to create a new folder.
- User should see an error instead of overwriting when a file or folder with that name already exists.
- User should be able to upload a folder from their computer into the project, keeping its structure; `node_modules`, `.git`, `vendor` and `.cache` inside it are skipped.
- User should be able to download the project as a zip (without `node_modules`, `.git`, `vendor` and `.cache`).
- User should be able to hide and show hidden files (names starting with a dot); the choice is remembered in their browser.
- User should be able to close the files panel from the menu.
- Only the project's owner (or an admin) should be able to create, upload or download files, and only inside the project's workspace.

## TAB-001: Workspace tabs

- User should be able to add tabs next to Preview from a "+" menu, and close them.
- User should be able to open a Console tab showing the app's dev-server output as it happens.
- User should be able to clear the console view.
- User should be able to open a Shell tab with an interactive terminal in the project's workspace.
- A Shell tab should keep its session while the user switches tabs.
- User should see a OneDrop banner with the project's name and a few shell tips when a Shell tab (or SSH session) starts, once per terminal.
- User should have modern command-line tools in the Shell tab: `bat` (view files with highlighting), `rg` (search), `fd` (find files), `z` (jump to directories), `jq` (JSON), `btop` (processes), `lazygit` (git), `micro` (editor, also used for commit messages), `vim` and `ncdu` (what's using disk space).
- User should see file listings (`ls`, `ll`, `la`, `tree`) with folders first, colours, a git column and relative times; `ls` with GNU-only flags (e.g. `-ltr`) should still work.
- User should see a clear notice when the console or shell isn't available (e.g. sandbox not running, or an older sandbox without a shell).

## PUB-001: Publish a project

- User should be able to publish a project from a Publish button in the workspace header.
- User should be able to choose who can open it: Private (people on the team's tailnet) or Public (anyone on the internet with the URL).
- User should see the status (publishing, live, failed), who published it and when, and the URL with a copy button.
- The button should say Republish once published; republishing can change who can open it.
- User should be able to unpublish.
- Each project should get its own URL (https://<project>.<tailnet>.ts.net), even when the platform runs on a laptop.
- The preview and published URLs should work with any framework's dev server without per-app host/origin configuration (requests reach the app as localhost).
- Recreating a published project's sandbox should publish it again automatically.
- Without a Tailscale auth key, user should be able to approve the project in Tailscale through a sign-in link in the Publish panel; publishing continues once approved.
- Approval should only be needed once per project (republishing reuses it).
- User should see a clear explanation if publishing fails (e.g. key rejected, Funnel not allowed for the tailnet).

## REM-001: Use the app builder remotely

- User should be able to use the app builder through a remote URL (e.g. a Tailscale Funnel to the machine running it), with every button and page working.
- Remotely, the preview should show the project's published URL when it has one, and otherwise explain that the preview only works on the machine running the app builder.
- Remotely, the Shell tab should explain that it only works on the machine running the app builder.

## TOOL-001: Tools tab

- User should see a Tools tab next to Preview; neither can be closed.
- User should see a menu of tool sections: Publishing, Domains, Monitoring, Database, Users & Auth, Growth, Feature Flags, Security, App Storage, Secrets, Integrations, Git, Agent Skills.
- User should be able to switch between sections; each shows what it's for.
- The Publishing section should show the project's current publish status, who can open it, and its URL.
- Sections not built yet should say they're coming soon.

## INV-001: Invite people

- User should be able to create an invite link from "Invite people" in the sidebar, optionally for a specific email address.
- User should be able to copy an invite link to the clipboard with one click.
- User should see their invites and whether each is waiting, accepted (and by whom), expired, or revoked.
- User should be able to revoke an invite that hasn't been used.
- Someone opening an invite link should land on sign-up, with the email filled in when the invite has one.
- Signing up through an invite should mark it accepted; signing up with the invited email should skip email verification.
- An invite link should work once and expire after 7 days; an expired, used, or revoked link should explain that clearly.
- Sign-up stays open to everyone; invites are a convenience.

## DEP-001: Run on a server

- An admin should be able to install the app builder on a fresh Ubuntu 24.04 server with one script, and redeploy new versions with one command.
- An admin should be able to provision that server on AWS with Pulumi.
- An admin should be able to make a user an admin from the command line (`php artisan zap:admin you@example.com`).
- An admin should be able to turn off email verification for sign-ups (for servers without outgoing email).
- Sandboxes on Linux should be able to reach the app to report agent events.

## GW-001: Previews and shells on a server

- On a server, user should see each project's preview and shell at their own HTTPS addresses (`preview-<id>.<domain>`, `shell-<id>.<domain>`).
- Only a logged-in user allowed to see the project should be able to open its preview or shell; everyone else is refused.
- The app's login cookie should never reach preview or shell addresses; each address gets its own cookie through a short-lived hand-off from the app, so sandboxed code can't read the user's login and a sandboxed app's cookies can't break the preview.
- User should see a "Reopen" link when a preview's access has expired.
- HTTPS certificates should only be issued for addresses of sandboxes that exist.

## HOME-001: Marketing home page

- Visitor should see what OneDrop does in one sentence at the top of the home page, with a button to start building.
- Visitor should see the positioning up top: vibe-code apps for production, bring your own subscription (a ChatGPT plan or an API key), deploy anywhere.
- Visitor should see a short animated demo of an app being described in chat, appearing in a live preview, and getting an instant Tailscale link with one click on Publish (shown still when reduced motion is on).
- Visitor should be able to pause and play the demo.
- Visitor should see a small prompt by the headline type a sentence and send it, then a single droplet fall from it and land and burst into code and app-part particles that fade away within a few seconds, leaving faint sparks drifting across the page.
- Visitor should see a full-size, swirling black hole boot up by the headline within about two seconds of the burst, and the hero's dot grid ripple with each impact. Nothing on the page gets pulled into the black hole.
- The black hole should be ray-traced (gravitational lensing, blackbody-colored accretion disk, Doppler beaming) with WebGL2, falling back to a simpler drawn black hole when WebGL2 isn't available.
- Visitor should be able to move the mouse to gently orbit the view around the black hole.
- Visitor should see a slowly turning spiral galaxy form around the black hole once it's full size: glowing cloud arms, stars of many colors and sizes, and a few stars with planets circling them, tilting with the black hole's view (only alongside the WebGL black hole).
- Visitor should be able to spot our solar system, labeled Sol, orbiting the galaxy along with its other stars: the Sun and all eight planets orbiting it (not to scale), with a blue-green Earth and rings around Jupiter, Saturn, Uranus, and Neptune, plus Voyager 1 and Voyager 2 spiraling out from Earth and heading off across the galaxy, each labeled.
- Visitor might spot two easter eggs among the stars: the Endurance from Interstellar spinning its ring as it orbits the black hole, and the Enterprise-D cruising the galaxy and now and then jumping to warp.
- Visitor should see small stars twinkle over the headline.
- Visitor should see the droplet in the header logo pop into a small burst of particles when hovering over it (never on its own), then bounce back, once the hero's drop has landed.
- None of the drop, particle, black hole, or twinkle motion should play when reduced motion is on.
- Visitor should see the tools it works with: Claude, OpenAI, OpenRouter, OpenCode, Docker, Blaxel, E2B, Daytona, Vercel, Tailscale, macOS and Linux laptops, and servers on AWS, Google Cloud, Hetzner, DigitalOcean, Vultr, or any Ubuntu machine.
- Visitor should see how it works in four steps and the main features in plain language.
- Visitor should see answers to common questions (coding knowledge, which AI, whether it's ready for production, where apps run, who can see them, cost).
- Visitor should be able to open a Product menu in the top bar (on hover or click) that lists each feature with a one-line summary, plus links to the demo, how it works, the tools it works with, and the self-hosting comparison; picking an item should close the menu and jump to that part of the page.
- Visitor should find a Docs link in the top bar and footer that opens the docs site (docs.onedrop.io).
- Logged-out visitor should be able to go to sign up or log in; logged-in user should see a button to open their dashboard instead.

## BRAND-001: OneDrop name and links in the app

- User should see the OneDrop name and droplet logo in the app's sidebar and header, not the starter kit's.
- User should see OneDrop in the browser tab title.
- User should find Repository and Documentation links that go to OneDrop's GitHub repository (github.com/onedrop-io/onedrop) and its docs site (docs.onedrop.io).

## HOME-002: Link previews when sharing

- User should see a preview card (image, title, and description) when pasting a link to the app into Slack, Facebook, X, LinkedIn, or iMessage.
- The preview image should be 1200×630 and match the home page's look.
- The preview should carry the same positioning as the home page headline.

## AGT-002: Choose the model and reasoning level

- User should be able to choose which model the agent uses for a project, from the providers they've connected.
- User should see each connected provider in a side rail, a search box, featured models first, and every other tool-capable model under "More models".
- User should be able to star models and find them under Favorites.
- User should see the models they chose most recently at the top of each provider's list, above the featured ones.
- User should be able to choose a reasoning level supported by the chosen model (e.g. Low, Medium, High, Extra high, Max), or leave it on the default.
- User should be able to pick the model when starting a new project, and change it in the project's chat at any time; the next message uses the new choice.
- The agent should use the key for the chosen model's provider, not just the default connection.
- The model list should come from the models.dev catalog (the one OpenCode uses), cached, and still work with the configured defaults if the catalog is unreachable.

## AGT-003: Stop, queue, and send now

- User should be able to stop the agent while it's working; the run ends in the sandbox and the chat says it was stopped.
- Output from a stopped run should never appear in the chat afterwards.
- User should be able to send messages while the agent works; they're queued, shown as queued, and run automatically one at a time when the current run finishes.
- User should be able to remove a queued message before it runs.
- User should be able to send a message immediately (⌘/Ctrl+Enter or the send-now button), which stops the current run and starts theirs; queued messages follow.
- Stopping should put any queued messages back into the message box instead of running them.

## AGT-004: Recall earlier prompts

- User should be able to press Up in an empty message box to bring back their last prompt, and keep pressing Up to go further back.
- User should be able to press Down to step forward again; going past the newest prompt clears the box.
- Up/Down should only recall prompts when the caret is on the first/last line, so moving around a multi-line message still works.
- Typing in the box should never be replaced by a recalled prompt.

## AGT-005: Formatted agent replies

- User should see the agent's replies formatted as markdown: headings, bold and italic text, lists, links, tables, inline code, and code blocks.
- Code blocks tagged with a language (e.g. ```php) should have syntax highlighting, in light and dark mode.
- Links in replies should open in a new tab.
- HTML in a reply should be shown as text, never run or rendered.
- User's own messages should stay plain text, as typed.

## AGT-006: Attach images and files to a message

- User should be able to attach files to a chat message by pasting, dragging them onto the message box, or picking them with the attach button, both in a project's chat and when starting a new project.
- User should see the attachments (image thumbnails, other files by name) before sending, and be able to remove any of them.
- User should be able to send attachments with or without text; up to 10 files, 10 MB each.
- User should see their attachments in the chat with their message, and open them; nobody who can't see the project can open them.
- The agent should see attached images along with the text, using a vision-capable model: the chosen model if it can read images, otherwise one from the same provider for that message (the chat says which). If none can, the agent works from the text and still has the files.
- The agent should get every attachment as a file in the sandbox, so when the user asks to use one in the app it copies it to the right place: the app's assets for fixed parts of the UI (logo, icons, backgrounds), or App Storage for content the app's users manage.
- Queued messages should keep their attachments when they run; stopping the agent drops attachments on queued messages and tells the user.

## MON-001: Monitoring

- User should see a Monitoring section under Tools with application and infrastructure panels.
- User should see requests over time, the number of unique visitors (IP addresses), HTTP status classes (2xx/3xx/4xx/5xx) over time, and a histogram of request durations.
- User should be able to choose the time range for each panel (past hour, day, or week).
- User should be able to show all traffic or only traffic from the published address.
- User should see CPU and memory use of the project's sandbox over time, as a share of its limits.
- Every chart should have hover details and an accessible table of its values.
- User should see a clear empty state when there's no data yet or the sandbox isn't running.
- Metrics should be collected inside the sandbox (no extra services) and survive sandbox recreation.

## DB-001: Database browser

- User should see a Database section under Tools that finds the app's databases on its own: SQLite files in the project, and `DATABASE_URL`/`DB_URL` or Laravel's `DB_CONNECTION` settings in `.env` files.
- User should be able to browse SQLite, PostgreSQL and MySQL/MariaDB databases, and switch between them when the app has more than one.
- User should see the database's tables and views, be able to search them, and open one to see its rows with column names and types, the primary key marked.
- User should be able to sort by a column, filter rows (equals, not equal, contains, greater/less than, is NULL, is not NULL), and page through them.
- User should be able to edit cells (including setting NULL), add rows and delete rows; changes stay pending until they save, and are saved together in one transaction or not at all.
- User should be able to discard pending changes, and be asked before leaving a table with unsaved changes.
- Views, and existing rows of tables without a primary key, should be read-only; binary and very long values should show a read-only preview.
- User should be able to run SQL in a SQL runner and see the rows it returns or how many rows it changed, with database errors shown as they come.
- The SQL runner should highlight SQL in the database's dialect and autocomplete keywords, table names and the column names of tables the statement uses while typing.
- Database passwords and connection details should stay inside the sandbox; the app builder only sees connection names.
- User should see a clear message when the sandbox isn't running, no database is found yet, or the database can't be reached.

## APPAUTH-001: Users & Auth for the app
- User should see a Users & Auth section under Tools that explains how to let people sign in to their app.
- User should be able to choose sign-in methods (email and password, Google, GitHub, Microsoft) and click "Set up with agent", which sends the agent a plain request in the chat (queued if it's working).
- The agent should build sign-in with the app's own stack, keep users in the app's own database, and follow a platform guide so the result works the same way in every project; it should describe the setup in `.zap/auth.json`.
- Once set up, user should see a Users tab listing the people who signed up (name, email, when they joined, when they last signed in), with search and paging, and a clear empty state.
- User should be able to edit a user's name and email, and delete a user (after confirming); changes go straight to the app's database, and a user who can't be deleted (other data depends on them) is explained.
- User should be able to add a user with a password and set a user's password; this goes through a small helper the agent adds to the app (`.zap/users`) so passwords are stored the way the app's sign-in expects. Without the helper, user should be offered to ask the agent to add it.
- User should be able to turn a user's account off (they can't sign in and are signed out) and back on, require a user to choose a new password next time they use the app, and sign a user out everywhere (also offered when setting their password). The app enforces these on every request; the panel sets `disabled_at` and `password_change_required` in the users table and asks the helper to end sessions.
- User should see which users are turned off or must choose a new password.
- For apps set up before an account control existed, choosing it should offer to ask the agent to add just what's missing.
- User should see each user's role and change it from the user's menu, choosing from the roles the app declares (admin and member by default).
- User should be able to sign in to the app as a user, in a new tab, to see what they see; the app shows who they're signed in as and a way to stop. This uses a one-time link from the app's helper, and only the project's owner can use it.
- User should be able to download the users (name, email, role, status, joined, last sign-in) as a CSV file.
- User should see join and last sign-in dates in their own time zone, whatever format the app's database uses.
- User should see a Configure tab with the sign-in methods, whether each is on, and an "Open sign-in page" link.
- For Google, GitHub and Microsoft, user should see the callback URLs to register with the provider (preview and published addresses) and be able to save the client ID and secret; they're written to the app's `.env` inside the sandbox (never stored by the app builder, never shown again), and the app restarts to use them.
- User should be able to turn methods on or off, which asks the agent to change the app.
- User should see a clear message when the sandbox isn't running or the app's users can't be read.

## GROW-001: Growth
- User should see a Growth section under Tools for reviewing how to grow their app and who visits it.
- User should be able to click "Run scan with agent" to have the agent check the app's SEO (titles, descriptions, headings, link previews, sitemap, robots.txt, image alt text, and so on), or "Scan and fix issues" to also fix what it finds; this sends the agent a plain request in the chat (queued if it's working).
- The agent should record the result in `.zap/seo.json`; user should see an SEO rating (0–100), when it was scanned, and each check with whether it passed, needs work, or failed.
- User should see visitors (unique IP addresses) with the change against the previous period, and how many of the app's users signed in during the period when the app has sign-in.
- User should see visitors over time, top pages, top referrers (other sites that sent visitors), top countries, top browsers and top devices.
- User should be able to choose the time range (past day, week, or 30 days) and show all traffic or only traffic from the published address.
- Countries come from the country header a CDN in front of the app adds (e.g. Cloudflare's); without one, user should see that there's no country data.
- Every chart should have hover details and an accessible table of its values; sections without data say so.
- Analytics should be collected inside the sandbox, by the same request log as Monitoring (no extra services).
- User should see a clear message when the sandbox isn't running.

## GROW-002: Custom analytics events
- User should see a Custom events section in Growth that explains how custom events show how people use their app, with a "Set up with agent" button.
- Clicking it sends the agent "Add custom analytics events to my project" in the chat (queued if it's working); the agent finds the app's key moments (signing up, creating things, finishing a flow) and records an event for each, following a platform guide so every app does it the same way.
- Events should never carry personal data: fixed event names and a few fixed-value properties, no emails, names, free-form text or IDs of people.
- The app should send events to its own address (`/__zap/event`); the sandbox's proxy records them to `.zap/events.log` and never passes them on to the app. Invalid or oversized events are dropped, and recording an event never breaks the app.
- The agent should describe each event in `.zap/analytics.json`.
- User should see each event with its description, how many times it happened, how many visitors triggered it, and the change against the previous period, for the chosen time range and traffic (all or published only); described events that haven't happened yet show zero.
- User should be able to pick an event to see it over time and its most common property values.
- Once set up, user should be able to describe more events to track and click "Add with agent".
- The agent uses the model chosen for the project (AGT-002); there is no separate plan to pick.

## SECRET-001: Secrets
- User should see a Secrets section under Tools listing the app's secrets (the variables in its `.env` file) by name, with values hidden.
- User should be able to filter secrets by name.
- User should be able to reveal a value, copy a name, and copy a value; values are only fetched from the sandbox when revealed or copied.
- User should be able to add a secret (name and value), change a secret's value, and delete a secret after confirming; the app restarts to use the change.
- User should be able to add several secrets at once: pasting `NAME=value` lines (like an `.env` file) into a name fills in a row per line, with quotes, `export`, comments and multi-line values handled; user can also add and remove rows by hand. They're saved together, with one restart.
- Names should be letters, digits and underscores, not starting with a digit, and not listed twice. A name that already exists should be marked as replacing its current value; the app builder only overwrites existing names the user saw marked that way.
- Values can be any text, including spaces, quotes and several lines, and should read back exactly as saved; other lines and comments in `.env` stay as they were.
- Secrets should stay in the app's `.env` inside the sandbox; the app builder never stores them.
- The agent should read secrets from the environment, never put their values in code or chat, and ask the user to add missing ones in Tools → Secrets.
- User should see a clear message when the sandbox isn't running or the secrets can't be read.

## FLAG-001: Feature flags
- User should see a Feature Flags section under Tools listing the app's flags, each with what it turns on, its key, and an on/off switch.
- User should be able to turn a flag on or off; the change is saved to `.zap/flags.json` in the sandbox straight away (no agent), and the preview and published app follow it without a restart.
- User should be able to describe a feature and click "Add with agent", which asks the agent in the chat (queued if it's working) to put that feature behind a new flag, following a platform guide so every app checks flags the same way.
- User should be able to click "Remove with agent" on a flag, which asks the agent to take the flag out and keep the feature as it is now (on or off).
- A flag the app checks but that isn't in `.zap/flags.json` should count as off.
- User should see a clear empty state when there are no flags yet, and a clear message when the sandbox isn't running or a flag no longer exists.

## STORE-001: App Storage
- User should see an App Storage section under Tools for files the app keeps, like uploaded photos, videos and documents, organized in buckets.
- With no buckets yet, user should see what App Storage is for and a Create bucket button; the dialog suggests a name they can change.
- Bucket names should be 3–63 lowercase letters, digits and dashes, starting and ending with a letter or digit; creating one that already exists should say so.
- User should be able to switch buckets, create another bucket from the bucket menu, and delete a bucket (and everything in it) after typing its name to confirm.
- User should be able to browse a bucket by folder, seeing each object's name, size and when it changed, plus the folder's item count and the bucket's total objects and size.
- User should be able to upload files and whole folders (with buttons or by dragging them in), create folders, search the whole bucket by name, and refresh.
- User should be able to preview images, download an object, copy its path, and delete an object or folder after confirming.
- Uploads should be up to 10 MB per file and replace an object at the same path; downloads and previews up to 25 MB.
- User should see a Commands view with code for reading and writing the selected bucket from the app (Node.js and Laravel/PHP), and be able to ask the agent to set up uploads with that bucket (queued if it's working), following a platform guide.
- Objects should live on the sandbox's disk outside the app's code (`$APP_STORAGE_DIR`, `/data/storage/<bucket>`), so they're not in git, the Files panel or the project zip; the preview and the published app use the same files, and they're kept when the sandbox is recreated with its files.
- With Docker sandboxes (laptops and servers), each project's buckets should be kept in a folder on the host (`storage/app/sandboxes/project-<id>/storage`), mounted into the sandbox, so they survive the container being deleted; sandboxes made before this should move their buckets there when they update.
- Paths should never reach outside their bucket, and only images are shown inline; everything else downloads.
- Only the project's owner (or an admin) should be able to see or change its storage.
- App Storage should load while the agent is working, and refresh when a run starts or ends.
- User should see a clear message when the sandbox isn't running or storage can't be reached.

## DEVTOOLS-001: Developer tools
- User should see a Developer section under Tools that links to Networking, Resources, and SSH (Connect and Keys) pages, each with a back button.
- Networking: user should see the preview URL and the published URL (when live), each with a copy button and a QR code for opening it on a phone. A preview that only works on the machine running the app builder should say so and show no QR code.
- Networking: user should see the ports open inside the sandbox (address, process name, PID) and what each is used for (preview, published address, shell, SSH), and be able to refresh the list. The page should explain that the preview shows the app on `$PORT`.
- Resources: user should see the sandbox's CPU and memory limits, and current CPU use (split into user and system) and memory use (split into active and cache), updated every few seconds with a small trend line for each.
- Resources: user should see the app's storage: the whole workspace, its code, its dependencies (`node_modules`, `vendor`), App Storage files, and the sandbox disk's use and size.
- SSH Keys: user should be able to add SSH public keys (a name and the key) to their account, see each key's type, fingerprint and when it was added, and delete a key after confirming.
- SSH Keys: keys should be validated (OpenSSH public key format, supported types); adding a key already on the account should say so. Private keys should be refused with a clear message.
- SSH Connect: with at least one key, user should see an SSH config entry and command to copy, and buttons to open the project in VS Code or Cursor over SSH. Without a key, user should be pointed to the Keys page.
- A project's sandbox should accept SSH logins only with its owner's keys (no passwords); adding or deleting a key should apply to the owner's running sandboxes right away.
- SSH should only be offered where it can be reached: on a laptop, from the same machine. On a server, or from another machine, user should see why it isn't available. An older sandbox without SSH should say to recreate it.
- User should see a clear message when the sandbox isn't running or its details can't be read.

## PRICE-001: Pricing page

- Visitor should be able to open a Pricing page from the home page's top bar and footer.
- Visitor should see four plans: Self-hosted (free, source available, no limits), Solo, Team, and Business, with Team highlighted.
- Visitor should see that every plan works with their own AI (a ChatGPT plan or an API key) with no markup, and that paid plans include monthly AI credits for people who haven't connected their own.
- Visitor should be able to switch between monthly and yearly prices; yearly is the default and saves 20%.
- Visitor should see the pricing promises (no markup on your AI, hosting never uses credits, published apps never pause, flat prices instead of per seat), a comparison with hosted builders, and pricing questions.
- Logged-out visitor should be able to start signing up from a paid plan, and open the install guide from the Self-hosted plan; logged-in user should see a button to open their dashboard instead.

## APPAUTH-002: Sign in with OneDrop
- User should be able to turn on "OneDrop accounts" as a sign-in method for their app, so people sign in to the app with their OneDrop account.
- Turning it on should set up the app's keys automatically (no provider console) and ask the agent to add the button.
- User should be able to choose who can sign in this way: everyone with a OneDrop account, or only members of chosen groups.
- Someone signing in should use their OneDrop login (signing in to OneDrop first if needed) and land back in the app signed in, with their name and email.
- Someone not allowed in should see a clear explanation and not be signed in to the app.
- It should follow OAuth 2.0 (authorization code, optional PKCE) with a user-info endpoint, so any stack's auth library can use it; codes work once and expire quickly, and only the app's own callback addresses are accepted.
- Turning it off should stop new sign-ins through OneDrop right away.
