# Feature flags

Follow this when the user asks you to put a feature behind a flag, or to remove a flag. The app builder's
Tools → Feature Flags panel lists the flags in `/workspace/.zap/flags.json` and switches them on and off by
editing that file, so stick to the contract below.

## /workspace/.zap/flags.json

```json
{
    "version": 1,
    "flags": [
        {
            "key": "new-checkout",
            "description": "The redesigned checkout page",
            "enabled": false
        }
    ]
}
```

- `key`: lowercase letters, digits and dashes, starting with a letter or digit (e.g. `new-checkout`). Never
  change a key once it exists.
- `description`: one plain-language line saying what the flag turns on. The user reads it in the panel.
- `enabled`: `true` or `false`. The user controls this from the panel. Don't change it for existing flags
  unless they ask you to.
- Keep the file valid JSON. Commit it with the app; it's part of the app's configuration, not a secret.

## How the app checks a flag

Every app checks flags the same way: through one small helper that reads `.zap/flags.json`.

- Read the file each time a request checks flags (once per request is fine), never cache it across
  requests, so a switch in the panel applies on the next page load without a restart.
- A missing file, invalid JSON, or a key that isn't in the file means **off**.
- Don't add a flag service or package (LaunchDarkly, Pennant, Unleash, …). If the user asks for one, do that
  instead of this guide.

By stack:

- **Laravel**: add `app/Support/Features.php` with `Features::enabled(string $key): bool` reading
  `base_path('.zap/flags.json')`. Use it in PHP code and Blade. For Inertia, share the flags as an
  `enabledFeatures` prop (a list of the keys that are on) in `HandleInertiaRequests`, and check them in React
  with a small `useFeature('key')` hook. Gate routes and actions on the server too, not only in the UI.
- **Front-end-only apps (Vite)**: add `src/features.ts` that imports `../.zap/flags.json` and exports
  `isEnabled(key)`. Vite reloads the page when the file changes.
- **Other stacks**: the same idea, in the stack's usual place for helpers.

If the app already has this helper, use it; don't add a second one.

## Adding a flag

1. Pick a short key from what the user described, and add it to `flags.json` with a description and
   `"enabled": false`.
2. Build the feature (or wrap the existing code) so it only shows or runs when the flag is on. When it's off,
   the app should behave exactly as it did before.
3. Check both ways: turn the flag on, check the feature works in the preview, then turn it off again and
   check the app still works.
4. Tell the user in one or two sentences what the flag controls, and that they can switch it on in
   Tools → Feature Flags.

## Removing a flag

The user says whether to keep the feature on or leave it off.

- Keep it on: remove the checks so the feature always runs.
- Leave it off: remove the checks and the feature's code (routes, pages, components, styles) that nothing
  else uses.
- Either way, remove the flag from `flags.json`, and remove the helper only if no flags are left and the
  user asked you to.
