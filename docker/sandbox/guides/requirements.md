## Requirements

Keep /workspace/.onedrop/REQ.md up to date: everything the user has asked this app to do, and every decision made along the way, with the reason. The user reads it in the Requirements tab to check that you understood them, and you read it to avoid undoing an earlier choice. It's the app's memory: what it has to be and why.

This file belongs to the platform. It never replaces the app's own README, SPEC.md or REQ.md, if it has them; leave those as they are unless the user asks.

### When

- Read it at the start of a turn that changes what the app does, before you change anything. If a request contradicts something in it, say so in one line and ask, unless the user clearly meant to change it.
- Update it in the same turn as the change, before you finish: a new feature, a change to one, a fix that says how something should behave, or a decision (theirs or yours) about how the app works.
- In that same turn, write or update the browser tests for each requirement you added or changed, and run them (see "Tests" below). A turn that changes what the app does isn't finished until its requirements have passing tests.
- Record decisions the user states in passing ("always…", "never…", "use X, not Y", "only admins can…").
- Skip it for questions, explanations and changes that don't touch what the app does (a typo, a refactor). Don't mention the file in your reply unless the user asks about it.
- Create it the first time there's something to record. For an app that already exists, only record what the user asks for from now on, plus decisions you can see they made; don't invent history.

### Format

```md
# Requirements

A two or three sentence summary of what the app is for and who uses it.

## Sign-in

REQ-001..002. People sign in with Google; only staff emails get in.

### REQ-001: Sign in with Google

- User should be able to sign in with their Google account.
- User should see an error when their email isn't a company one.

### REQ-002: Sign out

- User should be able to sign out from the menu.

### Decisions

- **2026-09-30: Only @acme.com emails can sign in.** The app is for staff; asked for when sign-in was added.
- **2026-09-30: No email and password sign-in.** Everyone has a Google account, and it's one less password to lose.
```

- One `##` section per area of the app (Sign-in, Orders, Admin…), with a one or two line summary that lists its IDs.
- One `###` heading per feature: `REQ-NNN: Short name`, then "User should be able to…" bullets (or "User should see…", "Admins should be able to…"): what someone can do, in the user's words, not how it's built.
- IDs count up across the whole file and are never reused, even after a feature is removed.
- Each area ends with `### Decisions`: one bullet per decision, the date (absolute, `YYYY-MM-DD`), the decision in bold, then why. Include what was ruled out ("no X, because…").
- A feature or decision that changes? Rewrite it in place (with the new date and reason for a decision); never leave two that contradict each other. A feature the user removes goes; add a decision saying it was removed and why.
- Plain language the user would use. No code, file names or jargon unless they used it.
