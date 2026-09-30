# Agent skills

Follow this when the user asks you to create an agent skill for the project. A skill is reusable
instructions an agent loads when a task calls for it (the open Agent Skills format). The app builder's
Tools → Agent Skills panel lists the project's skills and lets the user save a copy to their own skills,
so stick to the layout below.

## Where it goes

`/workspace/.agents/skills/<name>/SKILL.md`, plus any files the skill needs next to it (scripts, templates,
reference docs). Always use `.agents/skills`, even if the project also has `.claude/skills` or
`.opencode/skills`: the app builder makes every skill folder visible to every agent. Commit it with the app.

If a skill with that name already exists in any of those folders, update that one instead of making another.

## SKILL.md

```markdown
---
name: release-notes
description: Writes release notes from the git log in the team's format. Use when the user asks for release notes, a changelog entry, or what changed since the last release.
---

# Release notes

1. Run `git log --oneline <last tag>..HEAD`.
2. ...
```

- `name`: lowercase letters, digits and single hyphens, up to 64 characters, the same as the folder name.
- `description`: up to 1024 characters, in the third person. Say what the skill does **and when to use it**:
  agents only see the description until they decide to load the skill, so name the requests and words that
  should trigger it.
- The body: the steps, conventions and examples an agent needs, written for another agent. Keep it focused
  on one job and under about 500 lines; put long reference material in separate files and link them from
  SKILL.md with relative paths (`[the style guide](reference/style.md)`), saying when to read each one.
- Scripts the skill runs go in `scripts/`, and SKILL.md says how to run them. Prefer a script over
  instructions for anything that must be done exactly the same way each time.

## Don't

- Put secrets, API keys or personal data in a skill: it's committed, and the user may share it with
  everyone on the server. Point to environment variables instead (Tools → Secrets).
- Write a skill for something a single sentence in the chat would cover. A skill is for work that repeats.

When you're done, tell the user the skill's name, what it does, and that they can see it in
Tools → Agent Skills → Project skills, and save it to their own skills there to use it in other projects.
