## Demo video

Tools → Demo makes a 1080p demo video of the app from its browser tests (tests/e2e, see /opt/onedrop/guides/tests.md): a title card, then each scene (one test, re-run slowly) in a browser window with a caption, the cursor gliding to each click, and an end card. No audio. You write the storyboard; the platform records and renders it.

### The storyboard: /workspace/.onedrop/demo.json

```json
{
    "title": "Todos",
    "tagline": "Plan your day in seconds",
    "accent": "#4338ca",
    "scenes": [
        {
            "file": "tests/e2e/todos.spec.ts",
            "title": "Todos › User should be able to add a todo",
            "caption": "Add a todo in one click"
        },
        {
            "file": "tests/e2e/todos.spec.ts",
            "title": "Todos › User should be able to filter done todos",
            "caption": "Focus on what's left"
        }
    ]
}
```

- `title`: the app's name. `tagline`: what it does for the user, in one short line. `accent`: the app's main color (`#rrggbb`), for the background and highlights. Leave out `url`: the end card shows the published address on its own.
- `scenes`, in the order that tells the app's story (usually the main flow first: sign up, create the first thing, use it, then the extras), 3 to 8 of them, keeping the whole video under about 90 seconds. Each names a test by its file and its title exactly as the Tests tab and `node /opt/onedrop/tests.mjs status` list it (describe blocks joined with `›`, tags left out).
- `caption`: a few words in the user's language, saying what the user gets ("Share a list with your team"), not the test's title.
- Pick tests that pass and that show something on screen (clicks, forms, results), not ones that only check an error message or a redirect. If the story needs a test that doesn't exist yet, write it (tagged with its requirement like any other) and run it first.

### Rendering

- `/opt/onedrop/demo render` re-runs the scenes' tests slowly on the app's own dev server and database, then renders the video (a minute or two). It prints why when it fails: a scene's test failed (fix the app or the test, as for any failing test), or a scene names a test that no longer exists.
- When it's done, tell the user the demo is in Tools → Demo. Don't render after every change; only when the user asks for a demo, or asks you to update it.
