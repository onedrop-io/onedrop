# Adding custom analytics events

Follow this when the user asks you to add (or change) custom analytics events. Events show how people use the
app: who signs up, creates things, or finishes a flow. The app builder's Tools → Growth panel counts them from
the log the sandbox writes, and describes them from `/workspace/.onedrop/analytics.json`, so stick to the contract below.

## How events are recorded

The app sends each event to its own address, and the sandbox's proxy logs it (the app never sees the request):

```
POST /__onedrop/event
{"name": "project_created", "props": {"template": "blank"}}
```

- `name`: snake_case, starts with a letter, up to 64 characters (`signed_up`, `timer_started`).
- `props` (optional): up to 10 properties. Keys are snake_case; values are strings (cut to 100 characters),
  numbers or booleans. Anything else is dropped.
- The proxy answers `204`, or `400` when the event breaks these rules. It works the same in the preview and
  when published. There is nothing to install and no key.

## Add one small helper

Put a single `track` function in the app's front end (e.g. `resources/js/lib/analytics.ts` or
`src/lib/analytics.ts`) and call it everywhere. It must never throw or slow the app down:

```ts
export function track(
    name: string,
    props?: Record<string, string | number | boolean>,
): void {
    try {
        const body = JSON.stringify({ name, props });

        if (!navigator.sendBeacon?.('/__onedrop/event', body)) {
            void fetch('/__onedrop/event', {
                method: 'POST',
                body,
                keepalive: true,
            }).catch(() => {});
        }
    } catch {
        // Analytics must never break the app.
    }
}
```

If the app has a base path, prefix it. Record server-only outcomes from the browser once the response
confirms them (e.g. after sign-up succeeds), so every event goes through the helper.

## Choose the events

1. Read the app and find its key moments: account entry (sign-up and sign-in completed, not just opened),
   the main things people create, change or delete, and the steps of its main flows.
2. Track outcomes, not clicks: fire after the action succeeded. Aim for 5–15 events. Keep the names the user
   already has; add new ones only for new moments.
3. If the user described specific events, add those (and don't remove others unless they ask).

## Never send personal data

- No emails, names, phone numbers, addresses, IP addresses, user or record IDs, URLs with IDs in them,
  or anything a person typed (titles, descriptions, messages, search text).
- Property values come from a small fixed set the code controls: a plan name, a template, a step number,
  `true`/`false`. If in doubt, leave the property out.

## Write /workspace/.onedrop/analytics.json

List every event the app sends, in the order a person meets them:

```json
{
    "version": 1,
    "events": [
        { "name": "signed_up", "description": "Someone created an account." },
        {
            "name": "project_created",
            "description": "Someone created a project.",
            "props": ["template"]
        }
    ]
}
```

- `description`: one plain-language sentence a non-developer understands.
- `props`: the property keys the event sends, if any.

## Check it works

`curl -s -o /dev/null -w '%{http_code}' -X POST "localhost:$PROXY_PORT/__onedrop/event" -d '{}'` should print `400`
(the endpoint answers without recording anything). Don't send real test events; they would count as visits.
Make sure the app still builds and loads, then tell the user in one or two sentences which events you added
and that they show up in Tools → Growth as people use the app.
