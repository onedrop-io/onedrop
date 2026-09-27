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
- User should be able to connect Codex with an OpenAI API key.
- User should be able to connect OpenRouter by signing in to OpenRouter, with a small link to paste an API key instead.
- User should see an error when a key is rejected by the provider.
- User should be taken straight to the new-project prompt after connecting their first AI during onboarding.
- User should be able to continue to the app once at least one AI is connected.

## AI-002: Manage AI connections

- User should be able to see their connected AIs in settings, showing only the last few characters of each key.
- User should be able to replace a connection's key, choose the default AI, and disconnect an AI.
- Keys should be stored encrypted and never sent back to the browser.

## PRJ-001: Start a new project

- After logging in, user should see a "what are we working on today?" prompt with suggestions.
- User should be able to describe an app and submit it to create a project.
- User should be able to pick a suggestion to fill in the prompt.
- User should be taken to the project workspace after creating it.
- The project should be named from the description.

## PRJ-002: Project workspace

- User should see the chat on the left and the app preview on the right.
- User should see the agent's replies appear while it works, without reloading.
- User should be able to send follow-up messages to the agent.
- User should see the live app in the preview once it has a preview URL, and a placeholder until then.
- User should see their recent projects in the sidebar.
- User should not be able to see or message another user's project (admins can see all).

## SBX-001: A sandbox per project

- Each new project should get its own sandbox, started automatically.
- User should see the sandbox starting, then the live app in the preview once it's running.
- User should see an error in the preview if the sandbox fails to start.
- Sandboxes should run in local Docker in development and a managed provider (E2B/Daytona) in production, chosen by config.
- The user's default AI credential should be injected into the sandbox as an environment variable, not stored in the image.

## AGT-001: Real coding agent

- When user sends a message, OpenCode should run inside the project's sandbox using the user's default AI (Anthropic key, OpenAI key, or OpenRouter).
- User should see the agent thinking, then each step as it happens (reading, editing files, running commands) and its replies.
- The agent should remember the conversation across messages in the same project.
- User should not be able to send a new message while the agent is still working (one run at a time).
- The preview should switch from the placeholder to the app's dev server once the agent sets one up, and restart when the agent asks.
- User should see a clear message if the agent fails or their AI can't be used (e.g. Codex, not supported yet).
- User should see a plain explanation with a link to add credits when their AI account's balance is too low.
- Agent events should only be accepted from the project's own sandbox (per-sandbox secret token).

## FILE-001: Browse project files

- User should see the project's files in a panel on the right of the workspace, as a folder tree.
- User should be able to open a file and read its contents in a tab next to the preview.
- User should see new files appear while the agent works, and be able to refresh the list.
- User should be able to hide and show the files panel; it starts open only on wide screens.
- Large folders (node_modules, vendor, .git) should be listed but not expanded.
- Binary and very large files should show a notice instead of garbled or partial content.
- Only the project's owner (or an admin) should be able to read its files, and only inside the project's workspace.

## TAB-001: Workspace tabs

- User should be able to add tabs next to Preview from a "+" menu, and close them.
- User should be able to open a Console tab showing the app's dev-server output as it happens.
- User should be able to clear the console view.
- User should be able to open a Shell tab with an interactive terminal in the project's workspace.
- A Shell tab should keep its session while the user switches tabs.
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
- User should see a menu of tool sections: Publishing, Domains, Monitoring, Database, Users & Auth, Security, App Storage, Secrets, Integrations, Git, Agent Skills.
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
