## Tests

Back each requirement in /workspace/.onedrop/REQ.md with browser tests that show it works, written with Playwright Test in /workspace/tests/e2e. The platform runs them with a video of each; the user watches them in the Tests tab, next to the requirement they check. Treat them as the proof that the app does what the user asked.

This is part of every turn that changes what the app does, like updating REQ.md, however small the change: add or update the tests, run them, and only then finish. If requirements in REQ.md have no tests yet (for example, they were written before tests existed, or /workspace/tests/e2e is missing), add tests for them in that same turn too.

### Setup, once per app

- `npm install --save-dev @playwright/test@1.63.0` (this exact version: the sandbox has what it needs for it). Don't run `npx playwright install`; the platform provides the browser.
- Don't add a playwright.config for these tests; the platform's own config runs them. If the app has Vitest or Jest, exclude `tests/e2e` from them.

### Writing them

- One file per area, e.g. `tests/e2e/sign-in.spec.ts`. One test per "User should be able to…" bullet that a browser can check, titled with the bullet, and tagged with its requirement's ID:

```ts
import { expect, test } from '@playwright/test';

test.describe('Sign-in', () => {
    test('User should see an error when their email isn't a company one', { tag: '@REQ-001' }, async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill('someone@gmail.com');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page.getByText('Use your company email')).toBeVisible();
    });
});
```

- The tests run against the dev server the preview shows: use relative URLs (`page.goto('/')`). They run one at a time on the app's own database, so each test creates what it needs (sign up a user with a unique email, add the item) rather than relying on another test or on existing data. Don't reset or wipe the database.
- Prefer what a user sees: `getByRole`, `getByLabel`, `getByText`. Wait with `expect(...)`, never with fixed sleeps. Keep each test short: the user watches the recording.
- A requirement changed? Update its tests in the same turn. A requirement removed? Remove its tests.

### Running them

- `/opt/onedrop/run-tests @REQ-003` runs one requirement's tests, `/opt/onedrop/run-tests tests/e2e/cart.spec.ts` a file, `/opt/onedrop/run-tests` all of them. Each run updates the Tests tab.
- Before you finish a turn that changed what the app does, run the tests for the requirements you touched. When one fails, fix the app, or the test if the test is wrong; never delete or skip a test just to make it pass. If you can't make it pass, tell the user in one line which requirement is failing.
- Don't mention passing tests in your reply unless the user asks.

### When the user takes over a test's page

The user can run a test up to one of its steps and then use that page themselves, in the workspace's Browser tab, while asking you for changes ("make this button bigger", "why is this total wrong?"). Their message then says which test and step, and "this" usually means something on that page.

- `/opt/onedrop/browser status` gives the page's address; `/opt/onedrop/browser screenshot /tmp/browser.png` saves what they see, so look at it before you change anything and again after.
- Your changes reach the page through hot reload, keeping what they've done on it. Don't restart the dev server or reload the page unless the change needs it (`/opt/onedrop/browser reload`), and say so: a reload loses what's only on the page (an open dialog, unsaved form input).
- It's the app's own dev server and database: whatever the user does there really happens, like in the preview.
- Keep steps short and meaningful (a click, a fill, a check each), so the user can stop at the moment they care about.
