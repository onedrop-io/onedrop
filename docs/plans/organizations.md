# Plan: Organizations (multi-tenant hosting, same codebase as self-hosted)

Status: phase 1 (the boundary) built; phases 2–4 not started. Specs ORG-001..007; decisions in `REQ.md` → Organizations.

Anyone can self-host one install; we also run one hosted install for many companies. An organization is the
boundary between companies. Groups stay as teams inside one.

## Decisions (summary; reasons in REQ.md)

- Organizations always exist. Self-hosted has exactly one; no "org mode" switch in the code, only
  `APP_MULTI_TENANT` (`config('app.multi_tenant')`) gating sign-up-creates-an-org, "Create organization", and the
  switcher.
- A user can be in many organizations. The current one comes from the URL: `/o/{slug}/…` for an organization's
  own pages, and the project for `/projects/{id}/…` (those addresses are unchanged).
- Person-owned (no `organization_id`): users, passwords/2FA, passkeys, social accounts, SSH keys, AI
  connections, GitHub authorizations and installations, agent usages (for now).
- Org-owned (`organization_id`): projects (and through them sandboxes, tasks, messages, attachments, shares),
  groups, invitations, skills.
- `users.is_admin` = platform admin: install-wide settings only, no implicit access to org content.
  `organization_user.role` (owner/admin/member) = org admin.
- Billing is a later, separate piece of work.

## What phase 1 built

- `organizations` (name, slug), `organization_user` (role: owner/admin/member), `users.current_organization_id`,
  and a not-null `organization_id` on projects, groups, invitations and skills. The migration backfills one
  organization named after the install, with admins as owners.
- `Organization` (`multiTenant()`, `install()`, `createNamed()`, `addMember()`, `isManagedBy()`),
  `OrganizationRole`, `User::organizations()/organizationRole()/belongsToOrganization()/currentOrganization()`.
- `App\Concerns\BelongsToOrganization`: the relation, `inOrganization()` scope, and a fallback that puts a new
  record in its person's current organization when the creating code didn't say.
- `ResolveOrganization` middleware (alias `organization`, runs after route bindings): binds `/o/{organization}`
  (404 for non-members, and 404 for any bound group/invite from another organization), forgets the parameter so
  controllers keep their signatures, and records the current organization. Controllers read it with
  `ResolveOrganization::current($request)`.
- Policies: project and skill access is "owner and still a member, or an org admin"; outsiders get a 404 via
  `Response::denyAsNotFound()`. On self-hosted, platform admins count as org admins.
- Shared props `organization`, `organizations`, `multiTenant`; `useOrganization()` on the front end.
- ORG-007 fixes: OneDrop sign-in, its picker and `groups` claim, private published apps, skill sharing.

## Schema (as planned)

- `organizations`: id, name, slug (unique), logo_path/logo_mime (like ADMIN-001), timestamps.
- `organization_user`: organization_id, user_id, role, timestamps; unique (organization_id, user_id).
  Pivot model `OrganizationMember`, enum `OrganizationRole` (Owner, Admin, Member), like `GroupMember`/`GroupRole`.
- `users.current_organization_id` (nullable): where sign-in lands (ORG-002). Not used for authorization.
- `organization_id` (foreign key, indexed, cascade on delete) on: projects, groups, invitations, skills,
  github_installations, agent_usages. Migrate nullable, backfill, then make not-null (two migrations, works on SQLite
  and Postgres).
- Backfill migration: create one organization (named from the ADMIN-001 app name, slug from it), add every user as
  member, platform admins as owner (the first user if there is no admin), set every row's `organization_id` to it.

## Code (original plan)

Superseded where "What phase 1 built" differs: project routes kept `/projects/{id}`, and there is no
`URL::defaults` (Wayfinder would read the variable name as a literal default); the slug is passed explicitly.

- `App\Concerns\BelongsToOrganization`: `organization()` relation plus a `scopeInOrganization()`.
  Prefer explicit scoping through route binding and policies over a global scope. A global scope silently breaks
  queue jobs, commands, and the sandbox event endpoints that run without a current org.
- `App\Support\CurrentOrganization` (scoped singleton) set by `SetCurrentOrganization` middleware from the
  `{organization}` route parameter; 404 when the user isn't a member. Also sets `URL::defaults(['organization' => …])`
  so `route()` and Wayfinder calls inside an org don't need the slug passed everywhere.
- Scoped child bindings: `/o/{organization}/projects/{project}` resolves the project through the organization
  (`->scopeBindings()`), so a project id from another org is a 404 even for a member of both.
- Policies: replace `before()`'s `is_admin` bypass in `ProjectPolicy`, `GroupPolicy`, `SkillPolicy` with an
  org-admin check against the model's organization. `Gate::define('administer')` stays `is_admin` (platform);
  add `Gate::define('manage-organization', …)`.
- Routes: move the `auth` + `agent.connected` project, group, invite, skill, usage and org-settings routes under
  `Route::prefix('o/{organization:slug}')->middleware(SetCurrentOrganization::class)`. Leave unprefixed: auth,
  `/s/{share}`, `invite/{token}`, OAuth callbacks, `sandbox-gateway/*`, `sandbox-events/*`, `oauth/*` (they resolve
  the org from the project/token), user settings (`routes/settings.php`), and platform admin settings.
- Redirects: `GET /projects/{project}` and `/dashboard` → the org-prefixed URL (for bookmarks, notifications, emails).
- Broadcasting: `project.{project}` channel already goes through `can('view')`; the new policy covers it.
- `HandleInertiaRequests`: share `organization` (id, name, slug, role) and `organizations` (for the switcher);
  `multiTenant` flag.
- Front end: sidebar org switcher (hidden with one org), org settings in the settings modal (General, Members,
  Groups, Invites, Usage). Regenerate Wayfinder; most call sites pick up the slug from `URL::defaults`.

## "Everyone" fixes (ORG-007), the security-relevant part

- `SkillPolicy` / skill sharing queries: `shared` → shared within `organization_id`.
- `OneDropSignIn::allows()`: `onedrop_group_ids === null` → member of the project's org; group claims filtered to
  the project's org.
- `ProjectAuthController::oneDropSettings()` group picker: only the project's org's groups.
- `SandboxGatewayController`: membership of the project's org (via the project policy).
- `UserController` (USR-001) becomes org Members for org admins; the install-wide user list moves to platform admin.
- `InvitationController`: invites belong to the org; accepting joins it.

## Phases

1. **Boundary, no visible change.** Schema, backfill, `BelongsToOrganization`, policies, `/o/{slug}` routes,
   redirects, shared props, "everyone" fixes. Self-hosted looks the same except URLs. Tests: every area gets a
   cross-org test (member of org A gets 404 on org B's project, group, invite, skill, gateway, OneDrop sign-in).
2. **Members and settings.** ORG-004/005: members page, roles, leave, org invites, org name/slug/logo, org usage.
3. **Multi-tenant.** ORG-002/003/006: switcher, create organization, sign-up creates an org when
   `ONEDROP_MULTI_TENANT=true`, platform-admin organizations list. Browser test: sign up, create a second org,
   switch, check the first org's projects aren't there.
4. **Billing** (separate plan): flat plans per organization (REQ Marketing → pricing).

## Risks

- Every route and link changes in phase 1: a large diff. Keep it mechanical (route prefix + `URL::defaults`) and
  land it on its own.
- Queue jobs and sandbox callbacks have no current org; they must load it from the project, never from
  `CurrentOrganization`.
- A user deleted from an org keeps `user_id` on their projects; project ownership checks must be
  "owner **and** still a member" or org admin.
