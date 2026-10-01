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
- Deleting an account deletes every one of its projects the same way deleting a project does: apps go offline, and chats, attachments, and sandboxes are removed.
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
- User should see "Continue with GitHub" when only the GitHub App (`GITHUB_APP_CLIENT_ID`/`SECRET`) is set up; `GITHUB_CLIENT_ID`/`SECRET` override it with a separate OAuth app.
- User without a password should be able to open security settings without confirming a password, set a password, and delete their account without one.

## GRP-001: Create and browse groups

- User should be able to create a group with a name and optional description.
- User should become the owner of a group they create.
- User should see only the groups they belong to in the organization; its admins see every group in it.
- User should not be able to view a group they don't belong to.

## GRP-002: Manage group members

- Group owner should be able to add someone in the organization by email as a member or owner.
- Group owner should see an error when the email doesn't match anyone in the organization or the user is already a member.
- Group owner should be able to change a member's role and remove members.
- Member should be able to leave a group.
- A group should always keep at least one owner.
- Members who aren't owners should not be able to add, remove, or change roles.

## GRP-003: Edit and delete groups

- Group owner should be able to rename a group and change its description.
- Group owner should be able to delete a group.
- Members who aren't owners should not be able to edit or delete it.
- Organization admins should be able to manage any group in their organization.

## USR-001: Manage users (admin)

- Admin should be able to see a list of all users with their group counts.
- Admin should be able to grant or revoke admin access for other users.
- Admin should not be able to change their own admin access.
- Non-admins should not be able to see the users list.

## USR-002: See everything about a user (admin)

- Admin should be able to click a user in Settings → Users and see everything the install knows about them: account (joined, last sign-in and how, who invited them, email verified, password, two-factor, passkeys), organizations and roles, groups and roles, projects (with organization, agent, sandbox status, messages, tasks, git remote and published address), AI connections, sign-in providers, GitHub, invites they sent, agent skills, SSH keys and recent signed-in browsers.
- Admin should see a project's sandbox, publish and git sync errors on the user's page.
- Admin should see the user's AI usage: all-time cost, tokens and runs, and the past 30 days as a daily cost chart by agent, broken down by model and by project.
- Each sign-in should record when it happened and how (password, passkey, or the sign-in provider); a remember-me cookie picking a session back up doesn't count.
- Admin should be able to sign the user out everywhere (every session ends and remember-me stops working) and reset their two-factor sign-in, each after confirming; neither works on themselves.
- Admin should be able to open a listed project only when they could open it anyway.
- Admin should never see the user's secrets (password, AI keys, tokens).
- Non-admins should not be able to see a user's details or use these actions.

## USR-003: Impersonate a user (admin)

- Admin should be able to sign in as another user from their page in Settings → Users, after confirming, to see the app as they do.
- Admin should not be able to impersonate themselves or another admin.
- While impersonating, admin should see a banner on every page saying who they're signed in as, with a Stop impersonating button that signs them back in as themselves.
- While impersonating, admin should not be able to change the user's profile, password, two-factor, passkeys or sign-in providers, or delete their account.
- Impersonating should not count as the user's sign-in.
- Every impersonation should be recorded (admin, IP address, when it started and ended) and listed on the user's page; signing out while impersonating ends it.
- Non-admins should not be able to impersonate anyone.

## ORG-001: Organizations

- Every project, group, invite and skill should belong to one organization.
- User should only see and open things in organizations they belong to; anything in another organization should be a 404, even a group or invite opened under the address of another organization they're in.
- A self-hosted install should have one organization that everything already there moves into, and look the same as before (no switcher).
- User's profile, sign-in methods, SSH keys and AI connections should stay theirs in every organization they belong to.

## ORG-002: Switch organizations

- An organization's own pages (new project, groups, invites) should have addresses under `/o/<slug>/`; a project's pages keep `/projects/<id>` and are in the project's organization. Two tabs can be in two organizations.
- The sidebar should list the projects of the organization the page is in; new projects and search should stay in it.
- Opening an organization's page or a project should make it the user's current organization; signing in (and `/dashboard`) should take them there.
- User should see the organization they're in at the top of the sidebar, as its only header: its name and its logo. Without a logo it shows its initial on the hosted install, and the install's own logo (or the droplet) on a self-hosted one.
- User should be able to switch to another of their organizations from it, and land on that organization's new-project page; it should also open the organization's settings.

## ORG-003: Create an organization

- On the hosted install, someone signing up without an invite should get a new organization they own, named after them ("Ada's organization"); someone signing up through an invite should join the inviter's organization instead.
- On the hosted install, user should be able to create another organization from the switcher, with a name; they become its owner and land on its new-project page.
- On a self-hosted install, new sign-ups should join the one organization, and creating more should not be offered.

## ORG-004: Manage an organization's members

- User should see their organization's members (name, email, role, when they joined) in Settings → Organization.
- Organization owners and admins should be able to change a member's role between member and admin; only owners should be able to make someone an owner, or change or remove an owner.
- An organization should always keep at least one owner.
- On the hosted install, organization owners and admins should be able to remove a member, and a member should be able to leave. Leaving or being removed should take them out of the organization's groups and away from its projects at once; their projects should stay in the organization.
- On a self-hosted install, nobody should be able to leave or be removed from its one organization (an admin deletes the account instead).
- Members who aren't owners or admins should see the list but not change it.
- Invites should be for an organization: accepting one should join it (signing up first if needed).

## ORG-005: Organization settings

- Organization owners and admins should be able to change its name and its address (`/o/<slug>`: lowercase letters, numbers and dashes, not taken by another organization), and land on the new address.
- Organization owners and admins should be able to see its AI usage at `/o/<slug>/usage` from the account menu: the Usage page for every project in the organization, with a breakdown by person too.
- Organization owners and admins should be able to give it a logo (PNG, JPEG, WebP or SVG, up to 1 MB) in Settings → Organization, shown in the switcher, and remove it to go back to its initial. Only its members can load it.
- Groups should belong to an organization, and only its members can be added to them.
- Organization owners and admins should be able to manage every group and project in their organization.

## ORG-006: Platform admin

- Platform admins should keep the install-wide settings: sandbox providers, server, monitoring and backups.
- On the hosted install, platform admins should see every organization in Settings → Admin → Organizations: its name, address, owners, member and project counts, and when it was made, newest first, searchable by name or address. They shouldn't be able to open its projects from there.
- Being a platform admin should not give access to an organization's projects, groups or members on the hosted install; on a self-hosted install platform admins run its one organization. (Built.)

## ORG-007: "Everyone" means the organization

- Sharing a skill should share it with everyone in the organization, not the server.
- Sign in with OneDrop set to everyone should let in members of the project's organization only, and its group picker and `groups` claim should only list that organization's groups.
- Previews and shells should only open for the project's people, and a privately published app only for members of the project's organization.

## AI-001: Set up AI after sign-up

- After signing up, user should be asked to connect an AI before they can start building.
- User should first see a small set of AI icons (Claude, Codex, OpenRouter, Gemini, Ollama) to pick from, each with a few words on how it connects, and only then the setup for the one they picked.
- User should be able to go back and pick a different AI.
- User should be able to connect Claude with their Claude subscription (AI-005), with a small link to use an Anthropic API key instead.
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
- User should only be offered the models their ChatGPT plan includes with Codex (as ChatGPT lists them for their account, or the models OpenAI includes with Codex when it can't be asked), and projects should start on one of those.

## AI-005: Use a Claude subscription through Claude Code's own sign-in

- User should be able to choose "Use my Claude subscription" on the Claude card (the first option; pasting a key is the small link); OneDrop never asks for, receives or stores their Claude login or token (Anthropic requires sign-in to go through its own flow).
- User should be able to sign in from the project: "Sign in to Claude" above the chat box opens the Shell tab running Claude Code's own `claude auth login`.
- User should be taken back to the Preview tab once that sign-in succeeds (e.g. after pasting the code in the Shell and seeing "Login successful").
- User should see who they're signed in to Claude as above the chat box, once signed in.
- One sign-in should work in all of the user's sandboxes where the sandbox provider supports a shared login folder (Docker); elsewhere each sandbox signs in once. The folder is only mounted into sandboxes of projects the user owns.
- User should be told to sign in to Claude, or sign in again, when a Claude Code run finds they aren't signed in or their sign-in expired.
- User should see "Sign in to Claude" above the chat box whenever a message is waiting for them to sign in, even if the sign-in can't be checked right now (e.g. the sandbox is paused, or several chats are checking at once).
- When Claude rejects the sign-in (e.g. a token revoked because the shared login was refreshed elsewhere), the message should run once more by itself; if it's rejected again, Claude Code should be signed out in that sandbox, so the chat box stops saying they're signed in and shows "Sign in to Claude" instead.
- Once the user signs in (e.g. in the Shell tab), the chat should carry on by itself: the message that failed because they weren't signed in runs again, unless they've sent another since. This should also happen when the chat was reloaded or reopened while they signed in. If picking it back up fails (e.g. the sandbox or server was busy), the chat should keep trying while the message waits.
- Pasting a Claude subscription token (`sk-ant-oat…`) should be refused with a pointer to "Use my Claude subscription"; tokens saved before this change are deleted.
- Disconnecting the Claude subscription should sign Claude Code out in the user's running sandboxes and delete the shared login folder.

## AI-004: Connect Gemini with a Google AI Studio key

- User should be able to connect Gemini by pasting a Gemini API key, with a link to create one in Google AI Studio.
- The key should be checked with Google before it's stored, and user should see an error when Google rejects it.
- User should be able to pick Google's Gemini models in the model picker, and the agent should run on their key.
- Signing in with a Gemini (Google AI Pro/Ultra) subscription isn't offered: Google forbids third-party apps from using it.

## AI-006: Connect Ollama: an Ollama Cloud key or your own server

- User should be able to connect Ollama by pasting an Ollama API key, with a link to create one on ollama.com.
- The key should be checked with Ollama Cloud before it's stored, and user should see an error when Ollama rejects it.
- User should be able to pick Ollama Cloud's open models (GLM, Kimi, DeepSeek, Qwen…) in the model picker, and the agent (OpenCode) should run on their key.
- User should be able to choose "Use my own Ollama server instead" and enter its URL, plus a key if it needs one (sent as a Bearer token).
- The server should be checked before it's stored: user should see an error when it can't be reached, needs a key, rejects the key, or doesn't answer like Ollama.
- User should see an error for a private, loopback or internal address (sandboxes can't reach it, and the platform won't call it); a local install allows them, and in Docker sandboxes `localhost` means the user's machine.
- User should be able to pick any model on their server in the model picker, shown at no cost, and see the server's host on the connection instead of a key's last characters.

## AI-007: Platform checks run on Nimble on your own Ollama server

- When the project owner has connected their own Ollama server (AI-006) and pulled Nimble on it (`ollama pull nimble`), the platform's decision checks (Jev's: turn outcome, requirements kept, preview errors, test triage, failure causes, message checks and Auto's size) should be asked of Nimble on that server instead of Jev on OpenRouter, even when the owner also has an OpenRouter key.
- The checks should go to the server's `/v1/systemone` with the server's key, under the name Nimble has there (e.g. `nimble:latest`), through the same private-address guard as the rest of AI-006.
- A server without Nimble should leave the checks on OpenRouter as before (the owner's key, else the platform's).
- When Nimble errors or times out, the check should fail the way a Jev failure does, without asking OpenRouter instead.
- The hosted install's abuse check (PUB-003) should keep using the platform's OpenRouter key.

## PRJ-001: Start a new project

- After logging in, user should see a "what are we working on today?" prompt with suggestions.
- User should be able to describe an app and submit it to create a project.
- User should be taken to the project workspace after creating it.
- The project should be named from the description.

## PRJ-002: Project workspace

- User should see the chat on the left and the app preview on the right.
- User should be able to drag the divider between the chat and the workspace, the files panel's edge, and the left sidebar's edge to resize them (arrow keys work too; double-click resets). The widths are remembered in their browser.
- User should see the agent's replies appear while it works, without reloading.
- User should be able to send follow-up messages to the agent.
- User should see the live app in the preview once it has a preview URL, and a placeholder until then.
- User should see their recent projects in the sidebar.
- On a phone, the sidebar should close once the user picks a project (or any other page) in it.
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
- A deleted project's sandbox is removed from the provider it was created on, even if the configured provider has changed since.
- Renaming, regenerating the title, pinning, marking, and archiving should not change a project's place in "Recent".
- Admins opening someone's project should not mark it read for its owner.
- User should not be able to change or delete someone else's project.

## PRJ-004: Start from a template

- User should see templates for common business apps (CRM, project tracker, content calendar, inventory, hiring, events, help desk, time off, expenses) under the new-project prompt.
- User should be able to pick a template to fill in the prompt with a full description, then change it before sending.
- A project started from a template should be named after the template.
- Clearing the prompt should drop the template, so the project is named from what they type instead.

## PRJ-012: Browse templates from other registries

- User should see a few rows of popular open-source apps (from the configured registries, Dokploy's to start) under the built-in templates, with their logos, loaded after the page shows.
- User should be able to open "Browse all templates" beside the templates heading: a dialog listing the built-in templates and every registry's as one list, each with its name, description and logo or icon, not grouped or labelled by the registry it came from.
- User should be able to search the list by name, description or tag, and narrow it to a category (the most used tags, "Business" for the built-in ones).
- User should be able to pick a template to fill in the prompt, then change it before sending; the project is named after the template.
- A registry template (a Docker Compose stack) should start the project with its `docker-compose.yml` and its registry settings in the workspace, and the agent should fill in its variables, set the stack up as the preview and get it running.
- User should see registry templates (popular ones and in the dialog) as unavailable, with the reason, when new projects' sandboxes can't run Docker (SBX-008); picking one anyway should be refused.
- A registry that can't be reached should leave the built-in templates working; if a template's files can't be fetched when the project starts, the chat should say why and the agent shouldn't start.
- An admin should be able to add another registry in the same format, or turn one off, in config.

## PRJ-005: Search projects from the sidebar

- User should see a "Search" row at the top of the sidebar with a new-project button (pencil icon) beside it.
- User should be able to click Search to open a dialog, type part of a project's name, and see their matching projects (archived ones included), most recently updated first.
- User should be able to pick a result with the arrow keys and Enter, or click it, to open the project.
- User should only find their own projects.
- User should see icons beside the "Pinned", "Recent", and "Archived" headings.

## PRJ-006: See what each project is doing from the sidebar

- User should see a small colored tile with the project's initial beside each project in the sidebar; the same project always gets the same color.
- User should see a pulsing badge on a project's tile while its agent is working, a green badge when the app is published, and a red badge when its sandbox failed.
- User should see the agent's latest step in plain words under the project's name while it works (e.g. "Building the orders page", "Installing tools", "Testing the app", never a file path or a command), or "Working…" before its first step, updating without reloading. The same goes for tasks in the sidebar and on the board; the chat keeps the exact step.
- User should see "Sandbox failed" under the name when the project's sandbox failed.
- User should still see their pinned and recent projects as tiles when the sidebar is collapsed to icons, with the name on hover.

## NOTIF-001: Desktop notifications when a project is ready to review

- User should be able to turn on desktop notifications under Settings → Notifications; turning them on asks the browser for permission and then shows a "Notifications are on" notification, so they can see notifications get through.
- While the agent is working, user should see a prompt in the chat offering desktop notifications ("Turn on" / "Not now"), until they're turned on or the user picks "Not now" (remembered in their browser).
- User should get a desktop notification with the project's name when its agent finishes working, even while they're on another page or another tab; it says "Ready for your review".
- When the agent is waiting for the user (PRJ-011), the notification should say so instead: "Needs your answer", "Needs something from you", or "Got stuck and needs your help". The notification waits for that check (up to 20 seconds), then falls back to "Ready for your review".
- User should not be notified about the project they're looking at in a focused window.
- User should be able to click the notification to open the project.
- User should see why notifications can't be turned on when their browser doesn't support them or has blocked them for the app, and how to unblock them.
- User should be able to turn notifications off again; the choice is remembered in their browser.
- User should see the sidebar's unread dots update while any of their projects' agents are working, without reloading.

## PRJ-007: Project icons

- User should see their app's favicon on the project's tile in the sidebar (instead of its initial), with the status badge still on top.
- After each agent run, the icon should follow the app: if the agent added or changed the favicon (for example after the user pasted an image and asked for it to be the favicon), the sidebar shows the new one.
- The favicon should be found wherever the app keeps it, including in apps started from a repository: the usual places first (`public/`, `app/`, `static/`, the root), then any `favicon.*`, `icon.svg`/`icon.png` or `apple-touch-icon*.png` elsewhere in the app (for example `src/favicon.ico` or `apps/web/public/favicon.svg`), outside dependency and build folders.
- When the app has no favicon of its own (none, an empty one, or the Laravel starter kit's logo), the project's AI should draw a simple icon for it, which is shown in the sidebar and installed in the app as `public/favicon.svg`.
- The app's logo should follow its icon: in a Laravel starter kit app, the logo on its sign-in and sign-up pages and in its header shows the app's icon instead of the Laravel logo (unless the agent has drawn its own logo there).
- Projects on a Claude subscription should get icons drawn (and names suggested) too, through Claude Code in the sandbox.
- Drawn icons should be cleaned of anything that could run code (scripts, event handlers, external links) before they're stored or installed.
- User should be able to see the app's icon under Tools → App Icon, upload a PNG, JPEG, WebP or SVG to replace it, or have the AI draw a new one; the new icon is installed in the app and shown in the sidebar.
- Projects whose AI can't be asked (sandbox not running, no usable AI) should keep the initial until an icon is added.
- User should only be able to see and change icons of projects they can see and change.

## PRJ-008: See which chats are done and waiting

- User should see a blue dot and a bold title on a task in the sidebar when its agent replied since they last opened that task; opening the task clears it.
- User should see the project's dot while its main chat or any of its open tasks has an unseen reply; opening the main chat doesn't clear a task's dot, or the other way round.
- User should see the same dots in an opened project's sidebar, on "Main" and on each task.
- A reply that arrives while the user is in another tab or app should stay unread, even on the chat that's open; coming back to that chat clears it.
- Marking a project read (U) clears its tasks' dots too.

## PRJ-009: Start a project from a repository

- User should see a "Repository" toggle next to the agent picker on the new-project prompt; turning it on shows a repository field above the prompt and hides the templates.
- User should be able to type `owner/name`, paste a GitHub link (a `/tree/<branch>` link imports that branch), or any HTTPS git URL, and send it to create a project from that repository.
- When they've connected GitHub (the GitHub App), user should see their repositories (across their installations, most recently pushed first, private ones marked, empty ones left out) suggested as they type, and be able to pick one.
- When the GitHub App is set up but they haven't connected it, user should see "Connect GitHub" to import private repositories, which comes back to the new-project page with the Repository field open.
- The prompt should be optional with a repository; left blank, the agent is asked to get the imported app running in the preview.
- The project should be named after the repository, connected to it as its remote (through the GitHub App when the user can reach it that way, no token kept; otherwise without a token), and the repository's branch (its default one unless the link names another) should be brought into the sandbox before the agent starts.
- A repository that can't be reached (private without GitHub connected, or missing), has no commits, or isn't HTTPS should be refused with the reason, and no project made.
- If bringing it in fails after the project is made, user should see why in the chat, and the agent shouldn't start.

## PRJ-010: Sort the sidebar's projects

- User should be able to sort their pinned and recent projects by "Last updated" (the default), "Created", or "Manual" from a sort menu next to the "Recent" heading (or "Pinned" when there are no recent ones).
- User should be able to drag a pinned or recent project up or down to reorder it; dropping it switches the sort to "Manual" and keeps the new order.
- User should see projects they haven't placed yet (new ones) at the top of a manual order.
- Under "Manual", the "Recent" heading should read "Projects", since the list isn't by date anymore.
- The sort and the order should be remembered on their account, across devices.
- User should not be able to reorder someone else's projects.

## PRJ-011: See which chats are waiting for an answer

- After each agent turn, in the main chat and in tasks, the platform should ask Jev (TypeSafe's decision model on OpenRouter) how the turn ended: finished, asking the user a question, needing something from the user (a secret or key, a sign-in, a payment, a decision), or stuck (an error it couldn't fix, gave up).
- User should see why a chat is waiting under the project's name in the sidebar ("Needs your answer", "Needs something from you" or "Stuck", in amber), on each task in the sidebar, on "Main" in an opened project, and on the task's card on the board. A finished turn shows nothing new.
- The label should stay until the chat's next run starts, and not show while its agent is working.
- The check should use the project owner's OpenRouter connection when they have one, otherwise the platform's `OPENROUTER_API_KEY`. Without either, or when Jev can't be reached, isn't sure (below 50%) or doesn't answer, nothing changes: the turn shows as done, as before.
- A turn the user stopped, or that has no reply from the agent, shouldn't be labelled.

## SBX-001: A sandbox per project

- Each new project should get its own sandbox, started automatically.
- User should see the sandbox starting, then the live app in the preview once it's running.
- User should see an error in the preview if the sandbox fails to start.
- Sandboxes should run in local Docker in development and on a managed provider (Blaxel or Runtime Cloud) in production, chosen by config.
- The user's default AI credential should be injected into the sandbox as an environment variable, not stored in the image.
- User should see the app's styles and scripts in the preview and at its published address even when the app writes its own links as `localhost` (e.g. a Laravel app that doesn't trust proxies); the sandbox's proxy points those links, redirects included, at the address the visitor used.
- User should see an app in the preview even when it refuses to be shown in frames (e.g. Rails' or Django's `X-Frame-Options: SAMEORIGIN`, a CSP `frame-ancestors`); the sandbox's proxy drops those for the preview only, so the published address keeps the app's own protection.

## SBX-002: Sandboxes stay up to date

- When the sandbox image is rebuilt (new guides, tools or proxy), existing sandboxes should move to it without anyone running a command.
- Opening a project whose sandbox is older than the image should update it in the background, unless the agent is working.
- Before the agent starts a run, an outdated sandbox should be updated first, so the agent always has the current guides and tools.
- Updating keeps the app's files, App Storage, the agent's history, and everything in the sandbox user's home folder (such as a database or tools the agent installed there); the app restarts, and a published project is published again.
- Updated sandboxes should get the image's current shell setup (banner, prompt, aliases, prompt theme) even though the home folder is kept; lines the user added to `~/.bashrc` below the loader line, and a `~/.config/starship.toml` the user made, stay.
- User should see the sandbox updating in the preview (never the browser's "unable to connect" page from the old sandbox's address), then the app again.
- An update that fails or is cut off partway (a deploy, a queue timeout) should leave the project on its old sandbox with every file; the old sandbox is only removed once the new one has them all.
- Two updates of the same sandbox should never run at once.
- An admin should be able to update outdated sandboxes with `php artisan sandbox:update` (all, or one project).
- A sandbox updated by `php artisan sandbox:update` should be suspended once it's done (memory kept, woken by the next visit), so a batch of updates doesn't keep every new sandbox running at once.

## SBX-003: Sandboxes on Runtime Cloud

- With `SANDBOX_PROVIDER=runtime`, each project's sandbox should run on Runtime Cloud, from the sandbox image built there with `php artisan sandbox:build-image`.
- The sandbox's settings and the user's AI credential should reach it at start without appearing in any command line.
- User should see the preview and the Shell tab through private Runtime preview links, handed out only to people allowed to see the project; the links should be renewed before their tokens expire.
- An admin should be able to make Runtime previews public (`RUNTIME_PREVIEW_VISIBILITY=public`, paid sandboxes only), so they show inside the workspace: private Runtime previews can't be embedded in another site's page. Public links carry no token and are handed out only to people allowed to see the project.
- Pausing, resuming, updating (files kept) and deleting a sandbox should work as they do with Docker.
- A sandbox made from an older image should be reported as outdated, so SBX-002 updates it.
- Sandboxes should use the free trial unless `RUNTIME_FUNDING=paid` is set; Runtime errors should reach the user with Runtime's hint.
- A paused Runtime sandbox should wake on the next command even when Runtime briefly has no room for it (the trial's running limit, a full host): commands wait a few seconds and retry, then say to try again in a moment instead of failing outright.
- While an update copies a Runtime sandbox's files, the app's processes should be frozen (not the sandbox paused, which a copy would wake), so a database is copied in a consistent state; if the update fails or is cut off, they should carry on where they were.
- When the trial's limit on running sandboxes is reached, creating one should wait for a free slot (up to two minutes) instead of failing at once.
- Old versions of the sandbox image should be deleted from Runtime every hour and right before the image is built there (`php artisan sandbox:prune-images`, `--dry-run` to only list them), since Runtime charges for stored images and caps how many an account holds. Versions under the image's names from before the rename (`zap-sandbox`, `zap-probe`) should go too. The current version, any newer build, and every version a running, paused or persistent sandbox came from should be kept; a build should go ahead even if the old versions can't be deleted.

## SBX-004: Sandboxes on Blaxel

- With `SANDBOX_PROVIDER=blaxel`, each project's sandbox should run on Blaxel, from the sandbox image pushed there with `php artisan sandbox:build-image` (docker/sandbox plus Blaxel's sandbox API).
- The user's AI credential should reach the sandbox as a secret setting, never in a command line.
- User should see the preview and the Shell tab through private Blaxel preview links, handed out only to people allowed to see the project; the links should be renewed before their tokens expire.
- An agent run should keep its sandbox awake until it ends, even with nobody watching; idle sandboxes should go to standby by themselves.
- Updating (files kept) and deleting a sandbox should work as they do with Docker, and a sandbox made from an older image build should be reported as outdated.
- While an update copies a Blaxel sandbox's files, the app's processes should be frozen, so a database the agent set up is copied in a consistent state; if copying fails, or the update is cut off (e.g. by a deploy), they should carry on where they were.
- Blaxel's errors (such as account limits) should reach the user in Blaxel's words.

## SBX-005: Switch sandbox providers

- An admin should be able to switch where new sandboxes run by changing `SANDBOX_PROVIDER`, without breaking existing projects: each sandbox keeps being driven by the provider it was created on.
- Sandboxes on several providers should work side by side.
- A project whose sandbox is on another provider than the configured one should count as outdated, so it moves to the configured provider the way SBX-002 updates do (files, App Storage and home folder kept; the old sandbox removed only once the new one has them).
- An admin should be able to move every project now with `php artisan sandbox:update`, or one with `php artisan sandbox:update {project}`.
- Provider-specific behavior (renewing private preview links, the SSH notice) should follow each sandbox's own provider.

## SBX-006: Checkpoints and code backups

- User's changes should be committed to git in `/workspace` after every agent turn (finished or stopped), whichever agent ran it, with the prompt as the commit message; a turn that changed nothing makes no commit.
- A project without a git repository should get one (branch `main`) on its first checkpoint; one that already has a repository keeps its branch and history.
- Checkpoints should never include `node_modules`, `vendor`, `.cache` or `.env` files (except `.env.example`), whatever the app's `.gitignore` says.
- A repository in the middle of a merge or rebase should be left alone.
- After each turn the platform should copy the project's whole git history (a verified git bundle) to its backup disk (`SANDBOX_BACKUP_DISK`), outside any sandbox provider, and remember which commit it holds; a turn with no new commit copies nothing.
- A sandbox that gets a fresh workspace (recreated without its files, or its old sandbox is gone) should get the project's code back from the backup, with its branches, and start the app.
- Deleting a project should delete its backup.

## SBX-007: Idle Docker sandboxes are suspended

- A Docker sandbox nobody has used for a minute (`SANDBOX_DOCKER_IDLE_SECONDS`, 60 by default; 0 turns it off) should be suspended: its processes frozen with their memory kept, so it stops using CPU, the way Runtime and Blaxel sandboxes pause by themselves.
- An open workspace, the agent's events, gateway visits and the container's network traffic (such as its preview open in its own tab) should count as use; a sandbox whose agent is working, or whose project is published, should never be suspended.
- A suspended sandbox should wake by itself, carrying on where it was, when its project is opened, when its workspace is open, when the gateway sends someone to it, and before any command the platform runs in it.
- When user comes back to the workspace's tab and its sandbox had been asleep, the preview should reload by itself, and the chat and sandbox status should catch up on anything missed, without the chat scrolling.
- Using the workspace (clicking, typing, scrolling, or clicking into the preview or shell) should wake a sandbox that had fallen asleep and reload its preview the same way.
- A sandbox that stays suspended for a while (`SANDBOX_DOCKER_STOP_AFTER_MINUTES`, 5 by default; 0 turns it off) should be stopped, freeing its memory; it starts again by itself the same way, with its files, in a second or two, and should never be stopped while its agent is working or its project is published.
- Task copies (TASK-003) should be suspended and woken the same way.
- On Laravel Cloud, which has no Docker sandboxes, the idle check shouldn't be scheduled.
- A sandbox whose container was removed outside the app should show as failed, with an error saying so.
- A Docker sandbox stopped outside the app (Docker Desktop, a restart) should start again when its project is opened or its workspace is open, and its preview and shell links should keep working: each container keeps its host ports across restarts, and links are refreshed for containers made before that.
- A Docker sandbox whose host folders (its App Storage, or its user's Claude sign-in) were deleted while it ran should mend itself the next time it's woken (its project opened, its workspace open): the folders are made again and the sandbox restarts so it can write to them, and its preview reloads. Otherwise a Claude sign-in there says "Login successful" but doesn't stick.

## SBX-008: Run a project's own Docker Compose

- With Docker inside sandboxes turned on (Settings → Sandboxes → Docker), each sandbox should have its own Docker, so a project's own `docker-compose.yml` runs in it unmodified, and the agent and the Shell tab can use `docker` and `docker compose`.
- Sandboxes should only get `--privileged` for this on a local install; a server needs a container runtime that makes Docker in a container safe (such as Sysbox), and a privileged setting there should fail with a message saying so.
- Each sandbox's Docker should keep its images, containers and volumes on its own volume, deleted with the sandbox.
- A sandbox's Docker should start again when the sandbox restarts, so an app that runs on it (e.g. its database) comes back.
- Turning Docker inside sandboxes on or off should update existing sandboxes (files kept), as other sandbox changes do.
- `/opt/onedrop/compose init` should set a project up to start with its compose stack: it picks the compose files (the base file, its override, and an `arm64` overlay on arm64 machines), the web port the preview shows (or the one given), writes `.onedrop/dev`, and restarts the preview.
- User should see the chosen service in the preview; restarting the preview restarts the stack.
- `init` should say why a stack can't start (no compose file, a missing env file, a port the sandbox already uses).

## SBX-009: Project snapshots in object storage (planned)

- Each project's whole state should be kept in S3-compatible object storage (`SANDBOX_SNAPSHOT_DISK`), outside every sandbox provider: its workspace with uncommitted changes, the sandbox user's home folder (a database the agent set up, tools, the agent's history), App Storage, and its installed dependencies.
- A snapshot should be taken after every agent turn, before an update or a provider move, and once a day for any sandbox that ran that day; a layer that hasn't changed isn't uploaded again.
- A database in the sandbox should be snapshotted in a consistent state (the app's processes frozen while it's copied), and carry on where it was afterwards.
- Sandboxes should upload and download their snapshots straight to and from storage through short-lived signed links, never through the platform's servers, and never hold storage credentials.
- A new sandbox for a project, on any provider, should start from its latest snapshot, so updates, provider moves and a sandbox the provider deleted (an expiry, an outage) all keep the project's files; the old sandbox no longer needs to exist.
- Installed dependencies (`node_modules`, `vendor`) should come back from the snapshot, not be installed again, when the lockfiles haven't changed.
- An admin should see each project's last snapshot time and size, and be able to restore a project from an earlier one.
- Old snapshots should be deleted on a schedule (the last 10, plus one a day for 7 days); deleting a project deletes its snapshots.

## AGT-001: Real coding agent

- When user sends a message, OpenCode should run inside the project's sandbox using the user's default AI (Anthropic key, OpenAI key, or OpenRouter).
- User should see the agent thinking, then each step as it happens (reading, editing files, running commands) and its replies.
- The agent should remember the conversation across messages in the same project.
- Only one agent run should happen at a time per project; messages sent while it works are queued (see AGT-003).
- The preview should switch from the placeholder to the app's dev server once the agent sets one up, and restart when the agent asks.
- User should see a clear message if the agent fails or their AI can't be used.
- User should see a plain explanation with a link to add credits when their AI account's balance is too low.
- Agent events should only be accepted from the project's own sandbox (per-sandbox secret token).

## FILE-001: Browse project files

- User should see the project's files in a panel on the right of the workspace, as a folder tree.
- User should see a colored icon for each file and folder that shows its type (JavaScript, JSON, images, config, etc.).
- User should be able to open a file and read its contents in a tab next to the preview.
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

## FILE-004: Files panel follows changes from anywhere

- User should see files that are added, removed or renamed appear in the Files panel right away (within a second or so), whether the agent, the Shell or the running app made them.
- The sandbox should watch its files and tell the platform when files come or go; files inside `node_modules`, `.git`, `vendor` and `.cache`, SQLite journals and editor swap files shouldn't count, and neither should a file made and removed again straight away.
- The platform should push each change to the open Files panel (LIVE-001); the panel should only reload the tree after such a change, and only for the sandbox it's showing (Main's or the task's copy).
- Without a live connection, the Files panel should check for changes every few seconds instead, without calling into the sandbox, only while the panel is open and the page is visible.
- Sandboxes made before the watcher should keep reloading the tree as the agent works.
- Only the sandbox itself (through the signed address it was created with) should be able to report changes, and only for itself; only the project's owner (or an admin) should be able to check them.

## FILE-005: File and folder actions

- User should be able to open a "⋮" menu on any file or folder in the files tree, shown on hover, or by right-clicking it.
- User should be able to rename a file or folder, or move it into a folder by typing a path; an open file stays open under its new name.
- User should see an error instead of overwriting when the new name is taken, and a folder can't be moved inside itself.
- User should see a "Search files" box at the top of the files panel that filters the tree to file and folder names containing what they type, with their folders open; Escape clears it.
- User should be able to search inside one folder from its menu; the box shows that folder, and clearing it searches the whole project again.
- User should be able to add a file (opened in the editor) or a folder inside a folder, and to collapse all its child folders.
- User should be able to open a new Shell in a folder (a file's own folder, for files); the Shell only starts in folders inside the workspace.
- User should be able to copy a file's or folder's path, and a link to the project with a file open.
- User should be able to download one file as it is, or one folder as a zip named after it (without `node_modules`, `.git`, `vendor` and `.cache`).
- User should be able to delete a file, or a folder and everything in it, after confirming; an open file that's deleted closes.
- Only the project's owner (or an admin) should be able to rename, delete or download files, and only inside the project's workspace, never the workspace itself.

## FILE-006: Go to a file by name

- User should be able to press Cmd+P (Ctrl+P on Windows and Linux) anywhere in the workspace, including in the editor and in a Shell, or choose "Go to file…" from the files menu, to open a "Go to file" box with the cursor in it.
- User should be able to type letters of a file's name or path in order (e.g. `usctl` for `UserController.php`) and see matching files, best first: letters at word starts and in the file's own name rank highest, with the matched letters highlighted.
- User should see the files they opened most recently first before typing anything.
- User should be able to move through the results with the arrow keys and open one with Enter or a click; Escape closes the box.
- It should work with the files panel closed, list hidden files, and never list files inside `node_modules`, `vendor`, `.git` or `.cache`.
- User should see a message instead of results while the sandbox isn't running.

## TAB-001: Workspace tabs

- User should be able to add tabs next to Preview from a "+" menu, and close them.
- User should be able to drag Console, Shell, Requirements, Tests and file tabs into a different order; Tools and Preview stay first.
- User should be able to open a Console tab showing the app's dev-server output as it happens.
- User should be able to clear the console view.
- User should be able to open a Shell tab with an interactive terminal in the project's workspace, and open more Shells next to it (Shell 2, Shell 3…), each its own session.
- A Shell tab should keep its session while the user switches tabs.
- User should be able to type in the Shell tab straight away: the terminal gets the cursor when the tab opens or is switched back to.
- User should see a OneDrop banner with the project's name and a few shell tips when a Shell tab (or SSH session) starts, once per terminal.
- User should have modern command-line tools in the Shell tab: `bat` (view files with highlighting), `rg` (search), `fd` (find files), `z` (jump to directories), `jq` (JSON), `btop` (processes), `lazygit` (git), `micro` (editor, also used for commit messages), `vim` and `ncdu` (what's using disk space).
- User should see file listings (`ls`, `ll`, `la`, `tree`) with folders first, colours, a git column and relative times; `ls` with GNU-only flags (e.g. `-ltr`) should still work.
- User should see a clear notice when the console or shell isn't available (e.g. sandbox not running, or an older sandbox without a shell).

## LAYOUT-001: Hide the chat

- User should be able to hide the chat with a button at the left of the workspace's tab bar, so the workspace takes the whole width, and show it again with the same button.
- User's choice should be remembered in their browser across reloads and projects.
- A draft in the chat box should still be there when the chat is shown again.

## LAYOUT-002: Split the workspace into panes

- User should be able to split the workspace from a pane's split menu, side by side ("Split right") or one above the other ("Split down"), up to four panes; the new pane opens a new Shell.
- Each pane should have its own tabs, "+" menu and status; tabs added from a pane's "+" menu open in that pane, and anything else that opens a tab (a file, "Open shell here", the Tests of a requirement) opens it in the pane last used.
- User should be able to drag a tab onto another pane's tab bar to move it there; Tools and Preview move too but can't be closed and stay first in their pane.
- Moving a tab between panes should keep it as it was: a Shell keeps its session and the preview doesn't reload.
- User should be able to resize panes by dragging the line between them; double-clicking it makes them equal.
- User should be able to close a pane (when there's more than one); its tabs move into the pane next to it. A pane whose last tab is closed or moved away closes.
- "Open shell here" and "Sign in to Claude" should open a new Shell instead of restarting one that's open.

## LAYOUT-003: Put panes under the chat or the preview (planned)

- User should be able to drag a tab (e.g. a Shell) into a pane under the chat, or under the preview, and resize it.
- User's layout should be remembered for each project.

## LAYOUT-004: See the preview at phone and tablet sizes

- User should be able to choose Desktop, Tablet (768px) or Mobile (390px) from a size menu in the Preview's header.
- At Tablet or Mobile the app should show at that width, centered in the pane; Desktop fills the pane.
- Changing the size shouldn't reload the preview.
- User's choice should be remembered in their browser across reloads and projects.

## LAYOUT-005: Reloading keeps the workspace as it was

- User should see the same panes, tabs, pane sizes and showing tab after reloading a project's page, including Shells, the open file, the Tools section and the Tests tab's requirement.
- User's Shells should come back with the same session after a reload: what was on screen and anything still running in them are still there.
- User should see the preview on the page of the app it was on before the reload.
- User should find a half-typed chat message, the files panel's search and recently opened files (for "Go to file") still there after a reload, and the chat scrolled where it was.
- A new browser tab on the same project should start with the default layout and new Shells, not share the other tab's.
- A Shell tab that's closed should end its session.

## LAYOUT-006: Chat and workspace as tabs on small screens

- On a phone or small tablet, user should see Chat and Workspace tabs at the top of a project instead of the chat above the workspace, each taking the whole screen.
- User should be able to switch between them without losing the chat's draft or reloading the preview.
- User should see that the agent is working on the Chat tab while the Workspace tab is showing.
- Sending something to the chat from the workspace (a marked-up or inspected preview) should switch to the Chat tab.
- On a phone, the workspace's tab bar should leave out the preview's address, the preview size menu and the split menu, so its buttons fit on one line.

## LAYOUT-007: Tabs and header buttons on a phone

- On a phone, user should see one button for a pane's tabs showing the tab on screen and how many are open; tapping it opens a sheet listing the open tabs (to switch to or close) and the tabs that can be opened.
- The showing tab's own buttons (e.g. the preview's Inspect, Annotate and Reload) should stay in the bar however many tabs are open; "Open in a new tab" and the files toggle should be in a "⋯" menu.
- On a phone, the header's Commit and Share buttons should show only their icons, Publish keeps its label, and the project's name should stay on one line, cut short if it's long.
- Panels opened from the header (Share, Publish) should fit the phone's screen.

## PUB-001: Publish a project

- User should be able to publish a project from a Publish button in the workspace header.
- User should be able to choose who can open it: Private (the team: people on its tailnet, or signed in to OneDrop when published to the server's domain, see PUB-002) or Public (anyone on the internet with the URL).
- User should see the status (publishing, live, failed), who published it and when, and the URL with a copy button.
- The button should say Republish once published; republishing can change who can open it.
- User should be able to unpublish.
- Each project should get its own URL (https://<project>.<tailnet>.ts.net), even when the platform runs on a laptop.
- The preview and published URLs should work with any framework's dev server without per-app host/origin configuration (requests reach the app as localhost).
- Recreating a published project's sandbox should publish it again automatically.
- Without a Tailscale auth key, user should be able to approve the project in Tailscale through a sign-in link in the Publish panel; publishing continues once approved.
- Approval should only be needed once per project (republishing reuses it).
- User should see a clear explanation if publishing fails (e.g. key rejected, Funnel not allowed for the tailnet).
- If Funnel (public) or HTTPS certificates are off for the tailnet, user should see a link to turn them on in the Publish panel; publishing continues by itself once they're on.
- Publishing should never stay "Publishing…" forever: if it stops unexpectedly, user should see it failed and can try again.

## SHARE-001: Share a project to show it off

- User should be able to share a project from a Share button in the workspace header, which gives it a public page at `/s/<slug>` on OneDrop.
- Sharing should be off until the user turns it on; the slug includes a random part, so unshared projects can't be guessed.
- User should be able to choose what prompt the page shows (the project's first prompt by default) and edit it before and after sharing, so nothing private goes public.
- User should be able to choose which page of the app the screenshot shows (`/` by default).
- The share page should show the project's name, the owner's first name, the prompt, a screenshot of the app, how many prompts it took, and which agent built it. It should never show the rest of the chat, the code, or anything else.
- The share page should link to the running app only when the project is published as Public and live.
- OneDrop should take the screenshot in the project's sandbox and render a 1200×630 preview card from it (the prompt, the app, and the OneDrop mark), in the background.
- The screenshot and card should be kept on the app's default disk, so every OneDrop instance can serve them (e.g. object storage on Laravel Cloud).
- Pasting a share link into Slack, X, LinkedIn, iMessage, etc. should show that card, with the project's name and prompt as the title and description. Before the card is ready, the page should use OneDrop's own card.
- User should see the card, its status (rendering, ready, failed with a reason), the link with a copy button, quick links to post on X and LinkedIn, and how many views and remixes the page has had.
- User should be able to refresh the card (e.g. after the app changes); saving a new prompt or page refreshes it too.
- User should be able to stop sharing, which makes the page, its card and its screenshot 404 right away. Sharing again gives a new link.
- Deleting a project should delete its share page and images.
- Only the project's owner (or an admin) can share, change or stop sharing it.

## SHARE-002: Remix a shared project

- Anyone on a share page should be able to click "Remix this" to start their own project from the shared prompt.
- A visitor who isn't signed in should be taken to sign up (or log in), then land on the new-project page with the prompt filled in, after AI setup if they need it.
- A signed-in user should land on the new-project page with the prompt filled in and a note saying which project they're remixing, and can edit it before sending.
- Each remix should count toward the share page's remix total; views by the owner don't count as views.

## REM-001: Use the app builder remotely

- User should be able to use the app builder through a remote URL (e.g. a Tailscale Funnel to the machine running it), with every button and page working.
- Remotely, the preview should show the project's published URL when it has one, and otherwise explain that the preview only works on the machine running the app builder.
- Remotely, the Shell tab should explain that it only works on the machine running the app builder.

## TOOL-001: Tools tab

- User should see a Tools tab next to Preview; neither can be closed.
- User should see a menu of tool sections: Publishing, Domains, Monitoring, Database, Users & Auth, Growth, Feature Flags, Security, App Storage, App Icon, Secrets, Integrations, Git, Agent Skills.
- User should be able to switch between sections; each shows what it's for.
- The Publishing section should show the project's current publish status, who can open it, and its URL.
- Sections not built yet should say they're coming soon.

## INV-001: Invite people

- User should be able to create an invite link from "Invite people" in the account menu or settings, optionally for a specific email address.
- User should be able to copy an invite link to the clipboard with one click.
- User should see their invites and whether each is waiting, accepted (and by whom), expired, or revoked.
- User should be able to revoke an invite that hasn't been used.
- Someone opening an invite link should land on sign-up, with the email filled in when the invite has one.
- Signing up through an invite should mark it accepted; signing up with the invited email should skip email verification.
- An invite link should work once and expire after 7 days; an expired, used, or revoked link should explain that clearly.
- Signing up through an invite should join the inviter's organization.
- Sign-up stays open to everyone; invites are a convenience.

## DEP-001: Run on a server

- An admin should be able to install the app builder on a fresh Ubuntu 24.04 server with one script, and redeploy new versions with one command.
- An admin should be able to provision that server on AWS with Pulumi.
- An admin should be able to make a user an admin from the command line (`php artisan onedrop:admin you@example.com`).
- An admin should be able to turn off email verification for sign-ups (for servers without outgoing email).
- Local dev installs (`APP_ENV=local`) should skip email verification for sign-ups unless `AUTH_VERIFY_EMAIL` says otherwise.
- Sandboxes on Linux should be able to reach the app to report agent events.

## GW-001: Previews and shells on a server

- On a server, user should see each project's preview and shell at their own HTTPS addresses (`preview-<id>.<domain>`, `shell-<id>.<domain>`).
- Only a logged-in user allowed to see the project should be able to open its preview or shell; everyone else is refused.
- The app's login cookie should never reach preview or shell addresses; each address gets its own cookie through a short-lived hand-off from the app, so sandboxed code can't read the user's login and a sandboxed app's cookies can't break the preview.
- User should see a "Reopen" link when a preview's access has expired.
- HTTPS certificates should only be issued for addresses of sandboxes that exist.

## GW-002: Previews and shells through Cloudflare

- On Laravel Cloud (no Caddy), user should see each project's preview and shell inside the workspace at `preview-<id>.<domain>` and `shell-<id>.<domain>`, served by a Cloudflare Worker in front of the sandbox's provider (Blaxel or Runtime).
- Previews should load with their styles and scripts in every browser, Safari included, and on every provider: the browser only ever deals with onedrop.io addresses and cookies.
- Only a logged-in user allowed to see the project should get through, checked on every request (GW-001's hand-off and cookie); a copied link without that user's cookie should be refused.
- The provider's preview token should never reach the browser: the Worker adds it to each request it forwards.
- Only the Worker should be able to ask the app for a sandbox's address and token (a shared secret).
- Links in the app's pages that point at the provider's address should point at the preview address instead, and the Shell tab's live connection should work.

## HOME-001: Marketing home page

- Visitor should see what OneDrop does in one sentence at the top of the home page, with a button to start building.
- Visitor should see the positioning up top: vibe-code apps for production, bring your own subscription (a Claude or ChatGPT plan, or an API key), deploy anywhere.
- Visitor should see a short animated demo of an app being described in chat, appearing in a live preview, and getting an instant Tailscale link with one click on Publish (shown still when reduced motion is on).
- Visitor should be able to pause and play the demo.
- Visitor should be able to click Watch the demo by the headline to play the product demo video in a dialog, with playback controls, and close it with the close button or Escape.
- Visitor should see a small prompt by the headline type a sentence and send it, then a single droplet fall from it and land and burst into code and app-part particles that fade away within a few seconds, leaving faint sparks drifting across the page.
- Visitor should see a full-size, swirling black hole boot up by the headline within about two seconds of the burst, and the hero's dot grid ripple with each impact. Nothing on the page gets pulled into the black hole.
- The black hole should be ray-traced (gravitational lensing, blackbody-colored accretion disk, Doppler beaming) with WebGL2, falling back to a simpler drawn black hole when WebGL2 isn't available.
- Visitor should be able to move the mouse to gently orbit the view around the black hole.
- Visitor should see a slowly turning spiral galaxy form around the black hole once it's full size: glowing cloud arms, stars of many colors and sizes, and a few stars with planets circling them, tilting with the black hole's view (only alongside the WebGL black hole).
- Visitor should be able to spot our solar system, labeled Sol, orbiting the galaxy along with its other stars: the Sun and all eight planets orbiting it (not to scale), with a blue-green Earth and rings around Jupiter, Saturn, Uranus, and Neptune, plus Voyager 1 and Voyager 2 spiraling out from Earth and heading off across the galaxy, each labeled.
- Visitor might spot two easter eggs among the stars: the Endurance from Interstellar spinning its ring as it orbits the black hole, and the Enterprise-D cruising the galaxy and now and then jumping to warp.
- Visitor should be able to hover over the Earth in Sol to zoom in on it for about fifteen seconds: a realistic, sunlit Earth (real day, night, and cloud maps) turning under clouds that drift faster than real ones, with city lights and lightning storms on its night side, a massive hurricane in the Atlantic (from a real satellite photo, slowly turning), and the northern lights over the pole. Circling it are the ISS, a 1980s Space Shuttle, Hubble, a few satellites, a Starlink train, Starman's Roadster, and a realistic Moon (NASA's maps, with its craters catching the light) with an astronaut and an American flag on it (each labeled, going dark in the Earth's shadow). They orbit every which way: some round the equator, some steeply inclined or over the poles, and some going the other way round. Then it shrinks back into its orbit and the galaxy returns.
- Visitor should be able to hover over the Moon while the Earth fills the view (its close-up or the Earth level) to fly in to it: the Moon stops in its orbit, slides to the middle and grows as everything else fades, then dives through to NASA's black-and-white TV footage of the Apollo 11 moonwalk (Neil Armstrong's first step, Buzz Aldrin climbing down, planting the flag), shown like a 1969 TV picture with a readout and a caption for each part, for about half a minute, before pulling back out to the Earth. The Earth's close-up waits for it to finish, and it plays again only after the pointer leaves the Moon and comes back.
- Visitor should see a SpaceX Starship launch from Florida soon after the Earth fills the view, once Florida has come round the Earth's sunlit edge: it lifts off on a big plume of fire over a billowing cloud of smoke, leaving a white trail as it climbs and pitches over, drops its Super Heavy booster (which flies back to the pad), then heads out to the Moon and settles into orbit round it, passing behind and in front of it (labeled).
- Visitor should be able to hover over Mars in Sol for a ride on the Curiosity rover, about eighteen seconds: Mars grows into a realistic globe and turns to bring Gale Crater round, then dives down to the surface, where they look out of Curiosity's mast camera as it drives across the crater floor toward Mount Sharp (rocks, sand ripples, a hazy butterscotch sky, and the rover's own shadow ahead of it, with the camera's readout and today's sol), before pulling back out to the globe and shrinking back into its orbit.
- Visitor should get whichever planet or probe is nearest the pointer when they pass close by each other.
- Visitor should see Pioneer 10 and Pioneer 11 leave Earth and head off across the galaxy alongside the Voyagers, each labeled.
- Visitor should be able to hover over a Voyager or a Pioneer to see NASA's 3D model of it turning in the sunlight, then fly in to its Golden Record (Voyager) or plaque (Pioneer) and tour the engraving up close, one part at a time with a caption for each, before it shrinks back into the galaxy.
- Visitor should be able to zoom between Earth, the Solar System, the Milky Way (the usual view), and Laniakea by pinching or scrolling over the galaxy (at either end, once settled, scrolling goes back to scrolling the page), or with the scale beside it, which shows the current level.
- Visitor should be able to hover over Earth or Mars in the Solar System view for its close-up, growing out of the planet there, with the Solar System behind it.
- Visitor should see the Solar System view in 3D with real maps of every planet (Earth lit like its close-up, Saturn with its rings), an asteroid belt, and a big Sun that boils, flickers, throws flare loops off its edge, and now and then puffs out a plume of solar wind that spreads and dissipates within a few seconds, while lighting the planets; zooming down to Earth flies in to it.
- Visitor should see a comet on a long, thin, tilted orbit through the Solar System view, going round the same way as the planets: it wakes up as it comes in from beyond Neptune, whips round the Sun just outside Mercury, and fades away again on its way back out (never seeming to go backwards as the view turns). It has a dark icy nucleus in a glowing coma, with a curving dust tail and a straight blue ion tail pointing away from the Sun, longer and brighter the closer it gets.
- Visitor who changes the zoom level (pinch, scroll, or the scale) while a close-up is playing should get the new level right away, not wait for the close-up to finish.
- Visitor should see Laniakea as the real positions of about 41,000 nearby galaxies from the 2MASS Redshift Survey, turning slowly, with the ones inside Laniakea glowing warm, streams flowing toward the Great Attractor, and labels (You are here, Virgo Cluster, Great Attractor, Hydra, Perseus–Pisces, Coma).
- Visitor should see it drift out to Laniakea on its own every minute and a half or so when left alone, and come back.
- Visitor should see the Earth, Mars, and Moon maps credited in the footer (Solar System Scope under CC BY 4.0, and NASA's Scientific Visualization Studio), and the hurricane photo credited to its NASA photographer, plus NASA for the Voyager and Pioneer models and the Golden Record photo, and Oona Räisänen for the plaque drawing, and the 2MASS Redshift Survey for Laniakea's galaxies.
- Visitor should see small stars twinkle over the headline.
- Visitor should see the droplet in the header logo pop into a small burst of particles when hovering over it (never on its own), then bounce back, once the hero's drop has landed.
- None of the drop, particle, black hole, or twinkle motion should play when reduced motion is on.
- Visitor should see the tools it works with: Claude, OpenAI, OpenRouter, OpenCode, Docker, Blaxel, Runtime, E2B, Daytona, Vercel, Tailscale, macOS and Linux laptops, and servers on AWS, Google Cloud, Hetzner, DigitalOcean, Vultr, or any Ubuntu machine.
- Visitor should be able to click any of those tools to go to its website.
- Visitor should see how it works in four steps and the main features in plain language.
- Visitor should see answers to common questions (coding knowledge, which AI, whether it's ready for production, where apps run, who can see them, cost).
- Visitor should be able to open a Product menu in the top bar (on hover or click) that lists each feature with a one-line summary, plus links to the demo, how it works, the tools it works with, and the self-hosting comparison; picking an item should close the menu and jump to that part of the page.
- Visitor should find a Docs link in the top bar and footer that opens the docs site (docs.onedrop.io).
- Visitor should see an Install section with the one-line install command, and be able to switch it between their laptop, a server (an automatic sslip.io address), and their own domain, with a button to copy it and a note on what each does.
- Visitor should see the three steps after installing (run the command, create the admin account, connect an AI), what it needs (macOS or Linux, Docker, 5 GB of disk, an AI plan or key), how to update, and links to the install guide and the source on GitHub.
- Visitor should be able to jump to the Install section from the top bar, the hero's "Free to self-host", and the self-hosting comparison.
- Visitor should see a GitHub logo in the top bar and footer that opens the repository (github.com/onedrop-io/onedrop).
- Logged-out visitor should be able to go to sign up or log in; logged-in user should see a button to open their dashboard instead.

## HOME-003: Droppy, the easter-egg helper

- Visitor should see Droppy, a droplet with eyes that follow the pointer, pop up in the bottom-right corner about fifteen seconds after the galaxy appears, asking whether they'd like a hint for finding the home page's easter eggs.
- Visitor should be able to ask for a hint, and for another one, and get hints for eggs they haven't found yet (the Earth, the moonwalk, Mars, a Voyager, a Pioneer, the Solar System, the Starship launch, Laniakea, the logo droplet), plus the Endurance and the Enterprise, which play on their own.
- Visitor should be able to click Show me on a hint to be zoomed to the right level, or to see the planet or probe to hover over glow in the galaxy for a few seconds.
- Visitor should see Droppy cheer when they find an egg, with how many of them they've found, remembered in their browser across visits.
- Visitor should be able to close Droppy's bubble (Droppy stays in the corner; clicking it gives another hint), or choose Don't show Droppy again to send it away for good in that browser.
- Visitor shouldn't see Droppy with reduced motion on, on screens too small for the galaxy's zoom scale, or when the galaxy doesn't appear.

## BRAND-001: OneDrop name and links in the app

- User should see the OneDrop name and droplet logo in the app's sidebar and header, not the starter kit's.
- User should see OneDrop in the browser tab title.
- User should find Repository and Documentation links (under Help in the account menu) that go to OneDrop's GitHub repository (github.com/onedrop-io/onedrop) and its docs site (docs.onedrop.io).

## SET-001: Account menu and settings modal

- User should be able to click their name at the bottom of the sidebar to open an account menu with Settings, Invite people, Theme, Help, and Log out.
- User should be able to switch between light, dark, and system theme from the Theme submenu, and see the current theme next to it.
- User should see the dark theme by default until they pick another.
- User should find Documentation and Repository links under Help.
- User should see settings open in a modal with a left-hand list of sections: Account (Profile, Security, AI, Appearance, Notifications), People (Groups, Invite people), and, for admins only, Admin (Users, General, Sandboxes, Monitoring, Server, Backups; see ADMIN-001 to ADMIN-005).
- User should be able to move between sections without the modal closing, and link straight to any section.
- User should be able to close the modal with the close button or Escape and land back on the page they opened it from (the dashboard if they arrived by a direct link).
- Groups, Invite people, and Users should no longer be in the sidebar.

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
- User should be able to choose Auto instead of a model in the project chat, so the model and reasoning level are picked for each message (AGT-011).
- User should be able to pick the model when starting a new project, and change it in the project's chat at any time; the next message uses the new choice.
- User should see the agent, model, reasoning and Autofix controls fit a narrow chat: the model name shortens and labels drop to icons instead of running under the send button.
- The agent should use the key for the chosen model's provider, not just the default connection.
- The model list should come from the models.dev catalog (the one OpenCode uses), cached, and still work with the configured defaults if the catalog is unreachable.

## AGT-007: Choose the agent (OpenCode or Claude Code)

- User should be able to choose which agent works on a project, OpenCode or Claude Code, next to the model picker when starting a project and in the project's chat.
- User should only be offered Claude Code once they've connected Claude (an Anthropic API key or their Claude subscription), and only Claude models while it's chosen.
- User should be able to pick Claude Code right after connecting Claude in Settings → AI, without reloading the page.
- User should be able to build on their Claude Pro/Max subscription with Claude Code (AI-005); OpenCode can't use a Claude subscription.
- New projects should start on the agent and model the user last chose (when starting a project or in a project's chat), while they can still run it.
- Until the user chooses one, new projects should start on their AI subscription: Claude Code for a Claude subscription, OpenCode with Codex for a ChatGPT sign-in (their default connection first if they have both).
- Without a subscription, new projects should start on OpenCode, or on Claude Code when Claude Code is the only agent the user's connections can run.
- Starting a project without touching the picker should not pin the default as the user's choice.
- User should see Claude Code's thinking, steps and replies in the chat the same way as OpenCode's, and be able to stop it and queue messages.
- Claude Code should remember the conversation across messages; switching agents starts a fresh conversation (the project's files are kept) and the chat says so.
- User should see a plain explanation when Claude rejects the key, they aren't signed in to Claude, or their plan's usage limit is used up.

## AGT-009: Codex agent

- User should be able to choose Codex (OpenAI's own agent) as the agent, next to OpenCode and Claude Code, once they've connected Codex (ChatGPT sign-in or OpenAI API key), and only OpenAI models while it's chosen.
- User should still be able to use their Codex connection through OpenCode, by picking OpenCode and an OpenAI model.
- Codex should run on the user's ChatGPT sign-in without the refresh token entering the sandbox (the platform refreshes it, AI-003), or on their OpenAI API key.
- User should see Codex's thinking, commands, file changes and replies in the chat the same way as the other agents', be able to stop it and queue messages, and attach images for it to look at.
- Codex should remember the conversation across messages, and start a fresh one when its session is gone (e.g. a new sandbox).
- User should see a plain explanation when OpenAI turns down the sign-in or key, the ChatGPT plan's usage limit is used up, or the model isn't included with ChatGPT.
- Codex runs should count towards Usage (USAGE-001), with costs estimated at OpenAI's API prices.

## AGT-010: Unrecognized agent errors get a second look

- When an agent run fails with an error none of the known error texts match, and the chat shows the generic message ("Something went wrong: …" or "The agent stopped unexpectedly. Error: …"), the platform should ask Jev which known cause it is: a rejected key or sign-in, no credits or usage left, rate limited or overloaded, a model that isn't available, a conversation too long for the model, or a network error.
- When Jev is at least 70% sure of one of those causes, user should see the generic message replaced a moment later by that cause's message, in the agent's own wording (e.g. "Claude is overloaded right now. Try again in a minute." for Claude Code, "OpenAI turned down your ChatGPT sign-in…" for Codex on a ChatGPT sign-in).
- Errors the known texts already match should keep their message, and Jev shouldn't be asked.
- When Jev isn't sure, picks "something else", can't be reached, or there's no OpenRouter key (the owner's or the platform's), user should keep seeing the generic message. A message that changed in the meantime is left alone.

## AGT-011: Auto model and reasoning

- User should be able to pick Auto at the top of a provider's models in the project chat's model picker; the picker then reads "Auto" and hides the reasoning level.
- With Auto, each message should run on a model and reasoning level of that provider picked for how big the request is (a typo or tiny tweak, a small change, a new feature, a big redesign or hard bug): fast and cheap for small requests, the strongest with high effort for big ones.
- User should see in the chat which model and reasoning Auto picked for each run (e.g. "Auto picked Claude Sonnet 5 with low reasoning, for a small change").
- A queued message should run on the model picked for it when it was sent.
- Without Jev (no OpenRouter key, a timeout or an error), or when the picked model isn't one the user's connection can run, Auto should use the provider's default model.
- User should be able to turn Auto off by picking a model.

## AGT-012: Corrections while the agent works are sent now

- When the agent is working and the user sends a message with Enter (not send now, not ⌥/Alt+Enter), the platform should ask Jev whether it corrects or changes the work in progress, or is a separate request.
- A correction (Jev at least 80% sure) should stop the current run and start the message right away, and the chat should say "Sent now: it changes what the agent is doing".
- A separate request should be queued as before.
- Without Jev (no key, a timeout or an error), the message should be queued as before.

## AGT-003: Stop, queue, and send now

- User should be able to stop the agent while it's working; the run ends in the sandbox and the chat says it was stopped.
- Output from a stopped run should never appear in the chat afterwards.
- User should be able to send messages while the agent works; they're queued, shown as queued, and run automatically one at a time when the current run finishes.
- User should be able to remove a queued message before it runs.
- User should be able to send a message immediately (⌘/Ctrl+Enter or the send-now button), which stops the current run and starts theirs; queued messages follow.
- User should be able to always queue a message with ⌥/Alt+Enter; Enter queues it unless it corrects the work in progress (AGT-012).
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

## AGT-013: Draw on the preview to show what to change

- User should be able to click Annotate in the Preview's header to freeze the preview as a picture of what it shows right now (the page as scrolled, with anything open or typed in it).
- User should be able to draw on that picture with a pen, boxes, arrows and text notes, in red, yellow or blue, and undo or clear marks. Text is the tool picked when it opens.
- User should be able to drag any mark (by its line, its text or its number) to move it, whatever tool is picked; undo puts it back, and clearing can be undone too.
- User should be able to cancel, or click "Add to chat" to put the marked-up picture in the chat box as an attachment, and nothing else; the chat opens if it was hidden, ready for them to say what to change.
- The agent should be told, with the message but not in the chat, which page the picture is of and, for each numbered mark, the element it points at, written like a CSS selector with its text (`1. Box (red) around button#save ("Save changes")`), so it can find it in the code. Removing the picture before sending drops that too.
- When the preview can't take its own picture (the page doesn't answer), the browser should ask to share the tab and use that instead; declining should leave the preview as it was and say it couldn't take a picture.

## AGT-014: Inspect and rearrange the live preview

- User should be able to click Inspect in the Preview's header to turn on inspecting the live app: hovering highlights the element under the pointer with its selector and, for React or Vue apps in development, its component.
- While inspecting, clicks and drags go to the inspector, not the app; Esc or clicking Inspect again stops it and puts the page back as it was.
- User should be able to click elements to pick them; each pick is numbered on the page and listed in a panel over the preview, where they can add a note, pick its parent instead, or remove it.
- User should be able to drag an element onto another: dropped near the start or end of it, it moves before or after it; dropped in its middle, the two swap. The page shows the result right away (only in their browser, the app isn't changed), and the move is numbered and listed like a pick.
- User should be able to click "Add to chat" to attach a picture of the page as it looks then, with the picks and moves outlined and numbered, and nothing else in the chat box.
- The agent should be told, with the message but not in the chat, the page, and for each number the element (selector, text, component and its source file when known), the user's note, and for a move where it went ("swap with", "move before", "move after"), so it can make the change in the code.

## AGT-008: Laravel by default

- When the user doesn't name a stack, the agent should build the app on Laravel's React starter kit (sign-in, SQLite database, queues, realtime with Reverb, file storage), so it can grow without a rewrite.
- Asking for React, TypeScript, Tailwind, a website or a landing page should still get the Laravel starter kit (it uses them).
- When the user asks for a different framework by name or says they want no server, or the project already has an app, the agent should use that instead.
- The Laravel app should run in the preview on its one port, with its queue worker and Reverb, and the preview should show changes when the agent finishes.

## LIVE-001: The workspace updates live

- User should see the chat, the agent's progress, the sandbox's status, publishing and sharing update as soon as they change, without the page polling every second.
- User should see changes made elsewhere (another tab, a teammate, a background job) while the agent is idle too.
- The platform should push a "project changed" signal over WebSockets (Laravel Reverb) on a private channel per project; the page then reloads only its live data. Many changes in one request should send one signal.
- Only people who can see the project should be able to listen on its channel.
- When the live connection isn't available (not configured, or dropped), the page should fall back to polling as before, and a broadcasting failure should never break the request or job that caused it.
- Reverb should run locally with `composer dev`, on servers (behind Caddy on the app's own address) and on Laravel Cloud (its WebSockets cluster).

## LIVE-002: The preview updates while the agent works

- User should see the agent's changes in the preview as it makes them, not only when it finishes.
- Apps whose dev server has hot reload (Vite, Next.js…) should keep using it.
- Apps without it (the default Laravel stack builds assets with `vite build --watch`; plain PHP pages) should reload in the preview once a build under `public/build/` has been written or a PHP file changed (outside `vendor`, `storage`, `node_modules`, `.git`, `bootstrap/cache` and `.onedrop`), after writes have been quiet for a moment.
- A rebuild that writes the same files again (nothing in the app changed) should not reload the preview.
- The platform's own logs in `.onedrop` should never set off a rebuild, nor be committed to the project's git.
- Only preview pages should listen; published addresses should never reload by themselves.

## RT-001: Realtime in apps

- Visitors to an app should see live updates (WebSockets) in the preview and at its published address, whatever the framework.
- Servers that handle WebSockets on the app's own port should work with no setup.
- An app should be able to put a second local server (e.g. Laravel Reverb on 8080) behind the same address by mapping path prefixes to ports in `/workspace/.onedrop/routes.json`; HTTP requests and WebSockets under a prefix go to that port.
- Routes should never reach the sandbox's own services (the proxy, the web terminal, SSH), so they stay behind the platform's sign-in.
- The sandbox's PHP should have the extensions Reverb and queue workers need (`pcntl`).

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
- The agent should build sign-in with the app's own stack, keep users in the app's own database, and follow a platform guide so the result works the same way in every project; it should describe the setup in `.onedrop/auth.json`.
- Once set up, user should see a Users tab listing the people who signed up (name, email, when they joined, when they last signed in), with search and paging, and a clear empty state.
- User should be able to edit a user's name and email, and delete a user (after confirming); changes go straight to the app's database, and a user who can't be deleted (other data depends on them) is explained.
- User should be able to add a user with a password and set a user's password; this goes through a small helper the agent adds to the app (`.onedrop/users`) so passwords are stored the way the app's sign-in expects. Without the helper, user should be offered to ask the agent to add it.
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
- The agent should record the result in `.onedrop/seo.json`; user should see an SEO rating (0–100), when it was scanned, and each check with whether it passed, needs work, or failed.
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
- The app should send events to its own address (`/__onedrop/event`); the sandbox's proxy records them to `.onedrop/events.log` and never passes them on to the app. Invalid or oversized events are dropped, and recording an event never breaks the app.
- The agent should describe each event in `.onedrop/analytics.json`.
- User should see each event with its description, how many times it happened, how many visitors triggered it, and the change against the previous period, for the chosen time range and traffic (all or published only); described events that haven't happened yet show zero.
- User should be able to pick an event to see it over time and its most common property values.
- Once set up, user should be able to describe more events to track and click "Add with agent".
- The agent uses the model chosen for the project (AGT-002); there is no separate plan to pick.

## GROW-003: Visitor locations on a map

- User should see where visitors are on a world map in Growth's Analytics: countries shaded by visitors (more is darker in light mode, brighter in dark mode), and a bubble per city sized by its visitors.
- User should be able to hover (or focus) a country or city to see its name, region and country, and visitors.
- User should see a legend (visitors per country, city bubbles) and be able to open a table of countries and a table of cities.
- User should see top cities (city, region, country) next to top countries.
- Locations come from OneDrop's Cloudflare gateway (Cloudflare's own lookup: country, region, city, coordinates) or a CDN's location headers (Cloudflare, CloudFront, Vercel) in front of the app; without them, user should see that there's no location data.
- Coordinates should be rounded to about 1 km before they're logged, and a browser should never be able to set its own location through the gateway.
- The map follows the time range and traffic filter (all or published only), and draws without any map service or tiles.

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

## SECRET-002: Pasted secrets go to Secrets, not the agent

- When a chat message (main chat or a task's) contains a credential (an API key, password, token, a database URL with a password), the platform should ask "This looks like a secret. Save it in Secrets instead?" before sending it to the agent.
- User should see a suggested name (from the kind of key, an assignment like `NAME=value`, or the words before it) and be able to change it; the name field is focused.
- User should be able to save it: the value goes into the app's `.env` through Secrets (SECRET-001), and the message is sent with "(saved as NAME in Secrets)" in its place.
- User should be able to paste the value themselves when the platform can't tell which part of the message is the secret, and see an error when it isn't in the message, the name is invalid or already exists, or the sandbox isn't running.
- User should be able to send it anyway, or cancel (the message stays in the box).
- The message keeps its attachments until it's sent, and Jev is asked once per message.
- Without Jev (no key, a timeout or an error), the message should be sent as typed. Messages the platform writes (e.g. Feature Flags' agent actions) are never held.

## FLAG-001: Feature flags

- User should see a Feature Flags section under Tools listing the app's flags, each with what it turns on, its key, and an on/off switch.
- User should be able to turn a flag on or off; the change is saved to `.onedrop/flags.json` in the sandbox straight away (no agent), and the preview and published app follow it without a restart.
- User should be able to describe a feature and click "Add with agent", which asks the agent in the chat (queued if it's working) to put that feature behind a new flag, following a platform guide so every app checks flags the same way.
- User should be able to click "Remove with agent" on a flag, which asks the agent to take the flag out and keep the feature as it is now (on or off).
- A flag the app checks but that isn't in `.onedrop/flags.json` should count as off.
- User should see a clear empty state when there are no flags yet, and a clear message when the sandbox isn't running or a flag no longer exists.

## GIT-001: Git history

- User should see a Git section under Tools with the current branch and the project's commits, newest first: each with its message, who made it (the agent's turns marked as the agent's, see SBX-006), how long ago, and its short id.
- User should see the last backup time, and that dependencies, caches and `.env` secrets are never committed.
- User should be able to click a commit to see more: its full message, author and email, exact date and time, full id (with a copy button), and the files it changed with lines added and removed (binary files marked); clicking again closes it.
- User should be able to search the whole history by message, author ("agent" finds the agent's turns) or commit id, and see older commits with "Show more", 50 at a time.
- User should be able to click a changed file to see its diff in that commit, added lines in green and removed in red; very large diffs are cut short and say so.
- A project with no repository yet should show an empty history until its first commit.
- Only the project's owner should be able to see or change its git; the section should say so when the sandbox isn't running.

## GIT-002: Commit, discard and branches

- User should see uncommitted changes (made in the Shell, the Files panel, or while the agent was stopped) with their state: modified, added, deleted, renamed, new or conflicted.
- User should be able to click "Review and commit" (or a changed file) to open the same Commit changes dialog as the header's (GIT-006), and commit as themselves; dependencies and secrets stay out as with checkpoints. Committing with no changes should say why it can't.
- User should be able to discard the changes to one file, or all of them, after confirming; new files are deleted, ignored files (`node_modules`, `.env`) are kept.
- User should be able to switch branches and create a new one from the current commit; invalid branch names are refused, and the app restarts on the new branch.
- Committing, discarding, switching and restoring should wait while the agent is working.
- A repository in the middle of a merge or rebase should be pointed out.

## GIT-003: Restore an earlier version

- User should be able to pick "Restore this version" on any earlier commit and confirm; every file goes back to how it was then, saved as a new commit, so nothing is lost and today's version can be restored the same way.
- Uncommitted changes should be committed first ("Changes before restoring …"), the app restarts, and the project is backed up.

## GIT-004: Push to and pull from a remote

- With no remote, user should be able to create a new private (or public) repository on GitHub with a token, which connects it and pushes to it, or connect an existing repository on GitHub, GitLab, Forgejo or any HTTPS git host with its URL, an access token and an optional username.
- Remote URLs should be HTTPS without credentials in them, on the public internet unless the admin allows private networks (`SANDBOX_GIT_ALLOW_PRIVATE_REMOTES`).
- The token should be stored encrypted, never shown again, never sent to the browser, and never put in the sandbox: pushes and pulls run on the platform, carrying commits in and out of the sandbox as git bundles.
- User should be able to push the current branch and pull its new commits from the remote, in the background; the section shows Pushing…/Pulling…, then how many commits there are to push or pull, or why it failed (the remote has newer commits, a rejected token, a missing repository or branch).
- Pulls should only fast-forward; when both sides have new commits, user should be told to ask the agent to merge them. Pulling waits while the agent is working.
- User should be able to disconnect the remote (the repository itself is untouched).

## GIT-005: Connect GitHub with the GitHub App

- When the admin has set up a GitHub App (`GITHUB_APP_*`), user should see "Connect to GitHub" in Tools → Git, which opens a dialog instead of asking for a token.
- The first time, the dialog explains and sends the user to GitHub to install the app on their account or an organization and choose which repositories it can reach; GitHub sends them back to the project's Git section with the dialog open again.
- If the user comes back without GitHub's redirect (the app isn't set up to send people back, or they used the back button), the dialog should reopen asking "Finished installing on GitHub?" with Continue, which confirms the sign-in with GitHub and picks the installation up. A GitHub App whose Callback URL is the login one (`/login/github/callback`) should still bring Tools → Git returns back to the Git section.
- The platform should sign the user in through the app and keep their GitHub token encrypted (refreshing it when it expires), and remember only the installations GitHub says they can reach. When the token can't be refreshed, the dialog asks them to reconnect.
- Repositories should be listed and connected with the user's own token, so they only see and connect repositories both they and the app can reach; someone else's installation is refused.
- In the dialog, user should pick an owner (their account or an organization, with its avatar), then either:
    - **New repository:** a name suggested from the project, checked live for being free, and private or public. For an organization whose app can create repositories (Administration: write), "Create and push" creates it and pushes the current branch. For a personal account (GitHub Apps can't create those), "Create it on GitHub" opens GitHub's new-repository page filled in; when the user comes back, the new repository is found, selected and offered as "Connect and push" (with a link to give the app access to it first when the installation only reaches selected repositories).
    - **Existing repository:** search the owner's repositories, most recently updated first, marked private or public, pick a branch, and connect; a project with no commits brings the branch in (an import). The list refreshes when the user comes back from GitHub.
- The dialog should open on "New repository" for a project with commits and "Existing repository" for one without.
- Pushes and pulls for a GitHub-connected repository should use a short-lived installation token made on the platform when needed; no token is stored for the project, and none enters the sandbox. The connected remote shows as the GitHub repository with a link to it.
- Admins should see in Tools → Git what's wrong with the GitHub App setup (missing settings, missing Contents or Metadata permission, creating repositories unavailable without Administration permission, and installs on GitHub that never came back to OneDrop), with a link to the app's permission settings, and the Callback and Setup URL GitHub must use. Other users fall back to the token-based ways (GIT-004), which stay available as "Other git host".

## GIT-006: Commit and push from the header

- User should see a "Commit & push" button next to Share (just "Commit" with no remote), with a menu of Commit, Push and Create PR.
- User should be able to click it to open "Commit changes", then commit and push the branch when a remote is connected; with nothing to commit but commits to push, it just pushes. A toast says Pushing…, then Pushed or why it failed.
- The dialog should show the branch (marked "Default branch" on `main` or `master`), each changed file with its state and lines added and removed (binary files marked), and the total.
- User should be able to click Edit and untick files to leave them out of the commit; the count and total follow, and the files left out stay uncommitted.
- User should be able to leave the message blank and have the project's AI write one from the diff; when it can't, the message names the files ("Update app.tsx and 2 other files").
- User should be able to pick "Commit on new branch", name it, and commit there (the app restarts on it).
- User should be able to click a file to open its diff beside the list (the dialog widens): added lines in green and removed in red, a new file as all added lines, a binary file marked as such, and a new folder as its list of files; very large diffs are cut short and say so.
- With a diff open, ↑/↓ should move to the next or previous file and show its diff, Space should tick or untick it while editing, and clicking the file again, the close button or Esc should close the diff (Esc again closes the dialog).
- Commit should only be offered with uncommitted changes, and Push with a remote and commits it hasn't got; with no remote, the menu offers "Connect a repository…", which opens Tools → Git.
- Create PR should open a pull request for the current branch (GIT-007), and Combine should combine commits before pushing (GIT-008).
- The buttons should wait while the agent is working or the sandbox isn't running.

## GIT-007: Open a pull request with a written title and description

- On a pushed branch of a GitHub repository, user should be able to pick "Create PR" to open a dialog with the base branch (`main` or `master` first, any other branch to choose) and a title and description the project's AI writes from the branch's commits and diff; both can be edited.
- When the AI can't write them, the title should be the only commit's message (or the branch name), and the description should list the commits.
- "Open on GitHub" should open GitHub's new pull request page for the branch against the base, with the title and description filled in.
- Create PR should say why it isn't available: on the base branch itself ("Commit on a new branch first"), before pushing, or without a GitHub repository.

## GIT-008: Combine commits before pushing

- When the branch has more than one commit that isn't pushed yet, user should be able to combine them into one ("Combine N commits") from the header's menu, with a message the project's AI writes from them (editable), or their messages listed when it can't.
- Only commits that haven't been pushed should be combined, so pushing never has to overwrite the remote; the files are unchanged and nothing is lost from the working tree.
- Combining should wait while the agent is working, and be refused with uncommitted changes or while merging or rebasing.

## GIT-009: Choose hunks and lines, and discard a hunk

- In the commit dialog's diff, user should be able to untick a hunk, or click single added or removed lines, to leave them out of the commit; the file shows as partly included and the totals count only what's chosen.
- Committing should commit only the chosen parts of each file; what was left out stays uncommitted in the file.
- If a file changed after its diff was shown, the commit should be refused with "This file changed. Review it again." rather than committing something else.
- User should be able to discard one hunk of an uncommitted file after confirming, putting just that part back as it was in the last commit.

## GIT-010: Undo the last commit

- User should be able to pick "Undo last commit" from the header's git menu, or "Undo this commit" on the newest commit in Tools → Git, and see its message first; its changes come back as uncommitted, and the files don't change.
- Only a commit that isn't pushed yet should be undoable, so pushing never has to overwrite the remote; the first commit and merges can't be undone, and if the newest commit changed since it was shown, the undo is refused.
- Undoing should wait while the agent is working or a push or pull is running.

## GIT-011: Ask the agent about a change

- In a diff of uncommitted changes, user should be able to pick "Ask" on a hunk to put it, with the file's name, into the chat box, ready to type a question and send; the dialog closes and the cursor is in the chat box.

## GIT-012: Readable diffs

- Diffs (uncommitted changes and commits' files) should show the old and new line numbers, color the code by its language, and highlight the words that changed within an edited line.
- User should be able to hide whitespace-only changes in a diff; lines that only changed in spacing then read as unchanged, and picking or discarding parts waits until they're shown again.

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
- Visitor should see that every plan works with their own AI (a Claude or ChatGPT plan, or an API key) with no markup, and that paid plans include monthly AI credits for people who haven't connected their own.
- Visitor should be able to switch between monthly and yearly prices; yearly is the default and saves 20%.
- Visitor should see the pricing promises (no markup on your AI, hosting never uses credits, published apps never pause, flat prices instead of per seat), a comparison with hosted builders, and pricing questions.
- Logged-out visitor should be able to start signing up from a paid plan, and open the install guide from the Self-hosted plan; logged-in user should see a button to open their dashboard instead.

## APPAUTH-002: Sign in with OneDrop

- User should be able to turn on "OneDrop accounts" as a sign-in method for their app, so people sign in to the app with their OneDrop account.
- Turning it on should set up the app's keys automatically (no provider console) and ask the agent to add the button.
- User should be able to choose who can sign in this way: everyone in the project's organization, or only members of chosen groups in it.
- Someone signing in should use their OneDrop login (signing in to OneDrop first if needed) and land back in the app signed in, with their name and email.
- Someone not allowed in should see a clear explanation and not be signed in to the app.
- It should follow OAuth 2.0 (authorization code, optional PKCE) with a user-info endpoint, so any stack's auth library can use it; codes work once and expire quickly, and only the app's own callback addresses are accepted.
- Turning it off should stop new sign-ins through OneDrop right away.

## ERR-001: The agent sees the app's errors

- Errors should be recorded inside the sandbox in `/workspace/.onedrop/errors.log`, whatever the app's stack: server errors (5xx answers, with the page's text and the end of the dev server's log), errors in the preview's browser (uncaught exceptions, unhandled promise rejections, `console.error`, scripts or styles that fail to load), and the app not answering.
- Browser errors should only be accepted from the preview, never from the published address.
- The agent should be told where the log is and to check it before it finishes and when the user says something is broken.
- User should see a bar over the preview when the page hits an error, with the error and a "Fix it" button that sends it to the agent (queued if the agent is busy), and be able to dismiss it.
- After the agent finishes, the platform should load the app's home page and check for new errors from the preview; if there are any worth fixing, it should send them to the agent once, automatically. A run started by that automatic message shouldn't trigger another.
- When there's an OpenRouter key (the project owner's connection, else the platform's `OPENROUTER_API_KEY`), the platform should ask Jev which of the new errors are real problems in the app and send only those, leaving out noise (an error during a hot-reload rebuild or restart, a browser extension's, a missing favicon, a dev-only warning). If Jev judges them all noise, nothing should be sent. Without a key, or when Jev can't be reached or doesn't answer, every new error should be sent. The "Fix it" bar always sends the error the user clicked it for.
- User should be able to turn this automatic fixing on or off per project with an Autofix button in the chat's controls. It's on for new projects.
- Rebuilding a Laravel app's assets shouldn't break the preview while the build runs.

## TASK-001: Tasks that run in parallel

- User should be able to start a new task in a project; each task gets a fresh agent with its own chat, separate from the project's main chat.
- User should be able to message a task's agent while the main chat's agent, or other tasks' agents, are still working: they run at the same time in the project's sandbox, on the same files and preview.
- Messages sent to a task while its own agent is working should wait in that task's queue; stopping a task stops only that task's agent.
- User should see a project's open tasks under it in the sidebar, each showing when its agent is working, and a "+" to start a new task.
- User should be able to "Open" a project: the sidebar then shows just that project (Back, Main, Board, New task and its tasks by column) until they go back.
- User should be able to rename or delete a task; deleting a working task stops its agent first.

## TASK-002: Kanban board

- User should be able to see a project's tasks on a board with To do, In progress, Review and Done columns.
- User should be able to add a card to To do with a title (and optional notes) without starting an agent, then start it later; starting sends the card's title and notes to a fresh agent.
- A task should move to In progress when its agent starts, and to Review when the agent finishes a turn.
- User should be able to move a card to another column by dragging it or from its menu, and open a card to see its chat.

## TASK-003: Each task gets its own copy of the app

- A task's first message should make it its own copy of the app from Main's sandbox, whatever the app is built with: its files, dependencies and any databases kept in the sandbox, copied as they were at one instant so databases stay consistent. Main keeps running while it's copied (its processes pause for about a second).
- User should see the task's own preview, files, shell and tools on the task's page; changes there don't touch Main until they're applied.
- User should be warned in the task's chat when the app's settings point at a database or other data service outside the sandbox, which the copy still shares with Main.
- User should be able to apply a task's work to Main: it's merged with git, Main's agent is asked to do what the app needs (install dependencies, run migrations, restart) and to resolve any conflicts, the task moves to Done, and its copy is removed. A later message to the task makes a fresh copy from Main.
- User should be able to update a task from Main: Main's newer work is merged into the task's copy and the task's agent brings it up to date.
- User shouldn't be able to apply while the task's or Main's agent is working, or run more task copies at once than the project allows (3 by default).
- Deleting a task, or its project, removes its copy.

## TASK-004: Switching tasks keeps the workspace view

- The page's URL should say what the workspace shows: its tab, the Tools section, and the open file (e.g. `?tab=tools&tool=database`), so a reload or a shared link opens the same view.
- User should be able to switch between Main and a project's tasks (from the sidebar or the board) and see the same tab and tool on each, e.g. the Database tool, on that chat's own copy of the app.

## INSTALL-001: One-line install on your own computer

- User should be able to install OneDrop with one command (`curl -fsSL https://onedrop.io/install | sh`) on macOS or Linux, with Docker as the only requirement.
- User should be able to fetch the script from the short URL, which redirects to `install.sh` on GitHub's `main`, or straight from GitHub (`https://raw.githubusercontent.com/onedrop-io/onedrop/main/install.sh`).
- User should be told how to get Docker when it's missing, and the installer should start Docker Desktop on macOS when it's installed but not running.
- User should get a `drop` command to start, stop, update, see logs of, open, and uninstall OneDrop. Running the installer again updates it and keeps all data.
- OneDrop should run as a single container (`ghcr.io/onedrop-io/onedrop`) with SQLite, keeping its data in the `drop-data` Docker volume; projects run in sibling sandbox containers (`ghcr.io/onedrop-io/onedrop-sandbox`) on the `drop` Docker network.
- User should land on the sign-up page when the install finishes; the first person to sign up on a new install becomes its admin.
- User should find the install command on the home page, at the top of the README, and in the docs (introduction and install pages).
- Both images should be built for amd64 and arm64 and published by GitHub Actions whenever `main` passes its tests.

## INSTALL-002: One-line install on a server

- User should be able to install OneDrop on a Linux server with the same command, served over HTTPS at a domain: `--domain onedrop.example.com`, or `--domain auto` for a free `<ip>.sslip.io` address that needs no DNS. Run over SSH, the installer should offer the sslip.io address itself.
- OneDrop should get its certificates on its own (Let's Encrypt), and serve each project's preview and shell at `preview-<id>.<domain>` / `shell-<id>.<domain>` only to signed-in people allowed to see that project, the same way as the server install (GW-001). Sandbox ports should never be reachable from outside.
- User should be told which ports to open (80 and 443) and that DNS for the domain and `*.<domain>` must point at the server.
- The first account on a server install should only be creatable through the one-time setup link the installer prints, so a stranger who finds the server first can't make themselves admin. After the first account exists, sign-up works as usual.
- Running the installer again keeps the domain; `--domain` changes it and `--local` goes back to localhost only.

## USAGE-001: AI usage

- The platform should record the tokens and estimated cost of every agent run, per model, from Claude Code's result, OpenCode's steps and Codex's turns, charged to the project's owner (whose AI connection ran it).
- User should be able to open Usage from their account menu and see, for the past 24 hours, 7, 30 or 90 days (30 by default): the total estimated cost, how many agent sessions it came from, and each agent's (OpenCode, Claude Code, Codex) share of cost and tokens.
- User should see a chart of cost (or tokens) over the period, one line per agent, and be able to switch the page between Cost and Tokens.
- User should see totals: processed tokens, cached input, uncached input, output, and the share of input served from the cache.
- User should see a breakdown by model, by project, or by day, with each row's cost, share and tokens.
- User should only see usage their own AI connections paid for; costs are API-price estimates (what a subscription run would have cost on an API key).

## PUB-002: Publish to the server's own domain

- On a server install (INSTALL-002), user should be able to choose where to publish in the Publish panel: Your domain or Tailscale. The domain is the default; on a laptop only Tailscale is offered.
- Published to the domain, the project should get https://<name>-<id>.<domain>, with its own certificate. A project whose name reads like a preview address (e.g. "Preview") gets an `app-` prefix so it never takes over a preview.
- Public should let anyone open it without signing in; Private should let anyone in the project's organization open it (not only the project's people, and without having set up an AI). Someone who isn't signed in should be sent to sign in and then back to the page they asked for.
- User should see where it's published and who can open it (in the Publish panel, Tools → Publishing, and Developer tools).
- Moving a published project to the other target should take it down from the first. Unpublishing, or a failed publish, should stop it being served on the domain.
- With the Cloudflare preview gateway (GW-002, e.g. on Laravel Cloud), the Worker serves published apps the same way: public ones without a cookie (its answer shared for a minute), private ones by passing the sign-in redirect on. Names under the domain that only look like a published app still reach their own origin.

## PUB-003: Abuse check before going public (hosted install)

- On the hosted install (`APP_MULTI_TENANT=true`) with a platform OpenRouter key, OneDrop should ask Jev whether a project looks like abuse (phishing or impersonating a real brand or login page, a scam or malware, or something else clearly harmful) before it's published as Public, and after it's shared.
- Jev should see the project's name, its first and latest prompts, and the app's home page as a browser renders it: title, visible text, form fields and where its forms send. Each is cut short, and the check always uses the platform's key, never the owner's.
- User should see a public publish go live as normal when it looks fine; the check runs while the panel says Publishing….
- When it looks 80% likely or more to be abuse, user should see "Waiting for a review" in the Publish panel, with a plain explanation that a person will look at it and it goes live once approved; the app should not be served publicly meanwhile.
- A held project's share page (and its card, screenshot and Remix) should be a 404 until approved, and the Share panel should say it's waiting for a review.
- Publishing publicly again while held should stay held without asking Jev again.
- A project that's live as Public or shared should be checked again after the agent's next turns, at most once a day; one that's flagged then is taken offline for review the same way.
- If Jev fails or times out, or the page can't be read, the app should be published as normal (the page is just left out when it can't be read) and the error reported.
- Publishing privately, a self-hosted install, and an install without a platform key should never be checked.

## ADMIN-001: Name and logo

- Admin should be able to change the app's name from Settings → General, and see it in the header, sign-in pages, browser tab title and emails. (The sidebar shows the organization's name, ORG-002.)
- Admin should be able to upload a logo (PNG, JPEG, WebP or SVG, up to 1 MB) that replaces the droplet in the header, sign-in pages and browser tab, and in the sidebar of a self-hosted install whose organization has no logo of its own, and remove it to go back to the droplet.
- Non-admins should not be able to see or change these settings.

## ADMIN-002: Sandbox providers

- Admin should see every sandbox provider (Docker, Blaxel, Runtime Cloud) in Settings → Sandboxes as a list, each with an on/off switch and how many sandboxes it holds, next to a details pane for the selected one.
- Admin should be able to put the providers in order by dragging them (or with the arrow keys on a provider's handle); new projects run on the first provider that is turned on and has the settings it needs, marked Active. Existing projects move to it the next time they're opened (their files kept), as when SANDBOX_PROVIDER changes.
- Admin should be able to change a provider's settings in the details pane (image, CPU and memory, idle timeouts, region, and its API key); keys are stored encrypted and never shown again, and leaving a key blank keeps the saved one.
- Admin should see what a turned-on provider still needs (an API key, and a workspace for Blaxel) before new projects can run on it.
- Admin should not be able to turn off the last provider that is on and set up.
- Settings saved here should win over the ones in `.env`, and running queue workers should pick them up without a restart by hand.

## ADMIN-003: Server monitoring

- Admin should see the server's CPU use, memory used of total, disk space used of total, block I/O read and written, and network traffic in and out, in Settings → Monitoring, each as a current value and a chart over the past hour, day or week.
- The server should record a sample every minute (kept for a week; not on Laravel Cloud, which has no server of ours to sample); charts should show I/O and network as rates, and totals since the server started.
- Admin should see Docker's disk usage (images, containers, volumes and build cache: size and reclaimable), loaded on its own so the rest of the page doesn't wait for it.
- Admin should see how many projects, users and sandboxes (running and by provider) the install has.
- Metrics the server can't report (e.g. CPU and memory on macOS, Docker when it isn't installed) should say so instead of showing zero.

## ADMIN-004: Server domain and HTTPS

- Admin should see the address the app is served at, and whether it has HTTPS, in Settings → Server.
- On a server install (`infra/server/bootstrap.sh`), admin should be able to change the domain, the email Let's Encrypt sends certificate notices to, and the certificate authority (Let's Encrypt, or OneDrop's own for a LAN without public DNS); the server applies it in the background and everyone logs in again at the new address.
- Admin should be told that DNS for the domain and `*.<domain>` must point at the server first, and see which change is pending until it's applied.
- On other installs (a laptop, the one-line container install, AWS), admin should see how that install sets its address instead of a form.

## ADMIN-005: Database backups

- Admin should be able to back up the app's database (SQLite or Postgres) on a schedule to this server's disk or to S3-compatible storage (bucket, region, endpoint, keys, and a folder prefix), from Settings → Backups.
- Admin should be able to turn scheduled backups on and off, set the schedule (a cron expression, daily at midnight by default) and how many backups to keep (older ones are deleted after each backup).
- Admin should be able to run a backup now, see the list of backups (newest first, with size and date), download one, and delete one.
- Admin should see when the last backup ran and whether it failed, with the error.
- Admin should be able to restore a backup after typing the file's name to confirm; the current database is backed up first, and a restore replaces all of the app's data.

## ADMIN-006: Review held apps (hosted install)

- Platform admins should see apps the abuse check held (PUB-003) under Settings → Admin → Reviews, on the hosted install: the project, its owner (linking to their user page) and organization, what triggered the check, how likely each kind of abuse looked, and what Jev saw (prompts, page title, text, fields, form targets).
- Admin should be able to approve a held app: a held publish goes live, the share page comes back, and the approval stands until the owner sends the agent another message.
- Admin should be able to take an app down after confirming: its public app goes offline (the Publish panel says it was taken down after a review) and its share page is deleted.
- User should not be able to publish a taken-down app publicly or share it again until an admin approves it; publishing privately still works.
- Admin should see the latest decisions (who approved or took down what, and when), and be able to approve a taken-down app from there.
- Non-admins should not be able to see the reviews or decide them.

## SKILL-001: Agent Skills in Tools

- User should see an Agent Skills section under Tools listing the skills they can use in this project: their own, the ones others shared, and the project's own skills.
- User should be able to filter the list by All, Enabled (on in this project), Created by you, Shared with you, and Project skills, and switch between a grid and a list (remembered).
- User should be able to turn each of their own or shared skills on or off for this project; the agent uses the change on its next run, and the sandbox doesn't need to be running to switch.
- User should be able to open a skill to read its instructions and see its other files.
- User should be able to edit and delete their own skills (deleting asks first and turns it off everywhere); an admin can edit and delete anyone's.
- User should be able to share a skill with everyone in the organization, or stop sharing it (which turns it off in other people's projects). Others can turn a shared skill on in their projects but can't change it.
- Two skills with the same name can't both be on in one project; a project skill wins over one of the user's skills with the same name, and the list says so.
- User should see "No skills yet" when there are none, and a link to learn more about skills.

## SKILL-002: Add a skill

- User should be able to add a skill from the Add menu by writing one (name, description and instructions), importing one from a GitHub link (a skill folder, a SKILL.md, or a repository with one skill), or uploading a SKILL.md or a .zip of a skill folder. It's added to their skills and turned on in this project.
- User should be able to describe a skill and click "Create with agent", which asks the agent in the chat (queued if it's working) to write it as a project skill, following a platform guide.
- Names should be lowercase letters, digits and single hyphens, up to 64 characters, and not one the user already has; descriptions are required, up to 1024 characters. Imported and uploaded skills keep their files and SKILL.md as they are.
- Imports from GitHub use the user's GitHub sign-in when they have one, so private repositories work; a link to a folder with several skills should say to pick one.
- User should see a clear message when a link, file or skill isn't valid, or is too big (over 50 files or 1 MB).

## SKILL-003: Project skills

- User should see the skills in the project's repository (`.agents/skills`, `.claude/skills`, `.opencode/skills`) under Project skills, when the sandbox is running.
- User should be able to open a project skill to read it, and save a copy to their own skills (to share it or use it in other projects).

## SKILL-004: The agent uses the skills

- Before each run, the project's skills that are on should be put where the project's agent (OpenCode, Claude Code or Codex) looks for them, outside the project's files, so they never end up in its repository.
- Every agent should see every project skill, whichever of the three folders it's in.
- Skills the user turned off or deleted should be gone from the sandbox on the next run; skills the user put in the sandbox by hand stay.
- A problem putting skills in place should never stop the run.

## REQ-001: Requirements tab

- User should be able to open a Requirements tab from the "+" menu, next to Console and Shell.
- User should see there what they've asked the app to do, grouped by area, as "User should be able to…" items with IDs, plus the decisions made along the way with their date and reason.
- User should see the tab update after the agent works.
- User should see a short explanation when there are no requirements yet, and a notice when the sandbox isn't running.
- The agent keeps them in `.onedrop/REQ.md`, so an app's own `REQ.md` or `SPEC.md` is never overwritten.

## REQ-002: Turn requirements tracking on or off

- Requirements tracking should be on for every project unless turned off.
- While it's on, the agent (OpenCode, Claude Code or Codex) should record each change the user asks for and each decision (including ones stated in passing), before finishing its turn, and check new requests against earlier decisions, starting with the turn that first builds the app, whatever its stack.
- User should be able to turn it off or on from the Requirements tab or Tools → Agent Skills; the agent follows it from its next run. Turning it off keeps the file, and also stops the agent writing tests for them (TEST-002).

## REQ-003: Confirm changes to earlier decisions

- While requirements tracking is on (REQ-002) and `.onedrop/REQ.md` has decisions, the platform should ask Jev, before a chat message is sent, which decision (if any) it would undo or change.
- When Jev is at least 80% sure one does, the run shouldn't start; user should see "This changes an earlier decision: <decision>. Go ahead?" above the chat box.
- User should be able to click Go ahead, which sends the message and tells the agent the change is intended, so it rewrites that decision with the new date and reason.
- User should be able to click Cancel, which leaves the message in the box to edit.
- Without decisions, with tracking off, or without Jev, the message should be sent as before.

## TEST-001: Tests tab

- User should be able to open a Tests tab from the "+" menu, listing the app's browser tests (Playwright, in `tests/e2e`) grouped by the requirement each checks, with its name, then the tests not linked to one.
- User should see each test's latest result (passed, failed, not run yet) and how long it took, and a count of passed, failed and not run.
- User should be able to run all the tests, one requirement's tests, or a single test; the tab shows they're running and updates when they finish. Only one run goes at a time.
- User should be able to pick a test to watch the video of its last run, read why it failed, and download its Playwright trace.
- User should see a short explanation when there are no tests yet, the reason when the tests can't be listed or started (e.g. a syntax error, or Playwright missing), and a notice when the sandbox isn't running.
- Recordings stay in the sandbox and are never committed; only the latest run of each test is kept.

## TEST-002: The agent writes and runs tests for each requirement

- While requirements tracking is on (REQ-002), the agent should write a Playwright test for each "User should be able to…" a browser can check, titled with it and tagged with its requirement's ID (`@REQ-001`), and update or remove them when the requirement changes.
- The agent should run the tests of the requirements it touched before finishing its turn, fix what fails (never deleting or skipping a test to make it pass), and say in one line if one still fails.
- The agent should treat tests as part of every turn that changes what the app does, however small and on every stack (a plain Vite or static page too), including the turn that first builds the app, and add tests for any requirement that has none yet (e.g. ones written before tests existed).
- User should see in the Tests tab which requirements have no tests yet, and be able to click "Write them with agent" to ask the agent in the chat (queued if it's working) to write and run them.
- Every agent (OpenCode, Claude Code or Codex) and every stack should use the same setup: `@playwright/test` as a dev dependency, run by the platform's own config against the preview's dev server, one test at a time, with the sandbox's Chromium.

## TEST-003: Requirements show their tests

- User should see next to each requirement in the Requirements tab whether its tests pass, fail or haven't run.
- User should be able to click that to open the Tests tab on that requirement's tests.

## TEST-004: Watch and interact with tests in the test runner

- User should be able to click "Open test runner" in the Tests tab to open Playwright's UI mode in its own large window.
- User should be able to run all tests, a file or one test there and watch each step as it happens: the page at every action (before and after), the action list with timings, the test's source, console, network and errors.
- User should be able to inspect the page at any step, pick locators, and turn on watch mode so tests rerun when the agent (or anyone) changes them.
- Only people who can change the project should be able to open it; the runner should only open on the app's preview address with the token the app hands out, never on the published address.
- The runner should stop by itself after 30 minutes without use.
- User should see why the runner couldn't open (no tests, Playwright missing, it failed to start), and that it only opens on the machine running the app builder when the preview isn't reachable from theirs.

## TEST-005: Take over a test's page at a step

- User should see the steps of each test's last run (each action and check, with what it acted on) in the Tests tab.
- User should be able to click "Take over" on a step: the test runs up to and including that step, stops, and its page opens in a Browser tab, exactly as it was (form input, open dialogs, in-page state).
- User should be able to click, type, scroll, paste, and go back, forward or reload in the Browser tab, and open it in a bigger window.
- User should see the agent's changes arrive on that page through hot reload, keeping what's on it, while they chat about it; the agent should be told which test and step the page came from, and be able to look at it.
- User should see "Running the test up to that step…" while it gets there, and why when it can't (the test failed or ended first, or took too long).
- Closing the Browser tab should close the browser; it should also close itself after 30 minutes unwatched.
- Only people who can change the project should be able to take over a test; the page should only open on the app's preview address with the token the app hands out, never on the published address.

## TEST-006: Carry on with a taken-over test

- User should see in the Browser tab where the test is: paused after step N (and what's next), playing, finished, or which step failed and why.
- User should be able to click "Next step" to run the test's next step on the page as they left it, then pause again.
- User should be able to click "Play to the end" to run the rest of the test, a little slowed down so they can follow each step; the page stays open afterwards.
- A step that no longer matches the page (because the user changed it) should show as that step failing, with Playwright's reason, and the page should stay open.
- A test paused for longer than its usual timeout should still carry on when resumed.
- The agent should be able to carry on with the test too (`/opt/onedrop/browser resume step|end`) and see where it is.

## TEST-007: The platform checks the agent kept the requirements and tests

- After a main-chat turn, while requirements tracking is on (REQ-002), the platform should ask Jev (TypeSafe's decision model on OpenRouter) whether the turn changed what the app does, whether REQ.md records the request, and whether the agent wrote and ran tests for it.
- If the turn changed the app but REQ.md is missing or doesn't record it, tests weren't written or run, or a requirement has no tests, the platform should ask the agent once, in the chat, to catch up, naming the requirements without tests. A run started by that message shouldn't be checked again.
- The check should use the project owner's OpenRouter connection when they have one, otherwise the platform's `OPENROUTER_API_KEY`; with neither, there's no check.
- Nothing should be asked when Jev can't be reached or doesn't answer, or when the user has sent another message since.

## TEST-008: Why a test failed

- User should see, next to each failing test in the Tests tab (in the list and above its error), Jev's guess at why it failed: "Likely: the app broke", "Likely: the test is out of date" (the app changed on purpose and the test expects the old behavior) or "Likely: flaky or timing".
- The guess should come from the test's title, error, steps and source and the user's recent requests and the agent's recent actions, be worked out once per run of each test in the background (the tab never waits on it), and appear shortly after the run ends.
- User should see no guess when Jev isn't at least 50% sure, can't be reached or doesn't answer, or when there's no OpenRouter key (the owner's connection, else the platform's).
