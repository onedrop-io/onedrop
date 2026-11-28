# Publishing and deploying

Follow this whenever the user asks how to deploy, host, launch, go live, put the app online, share it, give it a
domain, or put it on a particular host (AWS, Heroku, Render, DigitalOcean, Netlify, Vercel, Fly.io, a VPS, their own
server…). OneDrop publishes apps itself, so answer with what OneDrop offers this project, not a generic deployment
tutorial.

## What's on offer here

/home/sandbox/.onedrop/publishing.json says what this project can use, fresh each turn
(`jq . /home/sandbox/.onedrop/publishing.json`):

- `published`: where it's published now (`target`, `url`, `visibility`, `status`), or null.
- `targets`: the places it can be published from the Publish button, each with who Private and Public mean there.
  `private` null means it can only be public; `unavailable` says why it can't be used right now.
    - `domain` (Your domain): the OneDrop server's own domain. Private means people signed in to OneDrop.
    - `hosting` (Hosting): the app runs on its own, off this sandbox, public. Only listed when it's set up.
    - `tailscale` (Tailscale): the team's tailnet. Private means people on the tailnet.
- `hosting`: null when Hosting isn't set up. Otherwise `platforms` (the "Host on" choices: Fly.io & Cloudflare,
  Vercel) and `accounts`, whose account each provider (Fly.io, Cloudflare, Neon, Upstash, Vercel) uses:
  `organization` (the organization's own, billed to it by the provider), `install` (OneDrop's), or `none`.

Only offer what the file lists. If it's missing, describe the options below without promising which are set up, and
say the Publish button shows what's available.

## Match the user's goal to an option

- **Show it to a teammate or try it on a phone:** the preview already works; Publish as Private (Your domain or
  Tailscale) gives the team a lasting address.
- **Show it off publicly:** the Share button makes a public page with a screenshot and the prompt, at `/s/…`, and
  links to the app when it's published as Public.
- **Make it public / launch it:** Publish → Public. On Your domain or Tailscale it's served from this sandbox, so it
  sleeps when the project is idle; Hosting keeps it running on its own, whether or not the sandbox is awake.
- **Run it for real users / production:** Hosting. A front end goes to Cloudflare, an app with a server to a Fly.io
  machine (or both to Vercel when the project picks it under Host on), with its database, Redis and files made at
  Neon, Upstash and R2 on the first deploy, data copied over once, scale to zero when idle, rollback, Update
  automatically and machine size in Tools → Publishing. Get the app ready first (guides/hosting.md) and make
  `/opt/onedrop/hosting check` pass.
- **Their own domain:** Tools → Domains, once published to Your domain or Hosting (Tailscale can't use one). It shows
  the DNS records to add and gets the certificate itself.
- **Their own cloud account / their own bill:** an organization owner or admin connects the organization's own
  Fly.io, Cloudflare, Neon, Upstash or Vercel account in Settings → Organization → Hosting accounts. New hosted
  things are then made there and billed to the organization by the provider, still deployed from Publish.
- **Vercel:** Publish → Hosting → Host on: Vercel, when it's listed in `hosting.platforms`; it can be their own
  Vercel account (above). Apps that keep data on disk go to Fly.io instead.
- **Scale:** a bigger machine in Tools → Publishing, or Move to Postgres there for an app on SQLite.
- **Hosting isn't set up** (`hosting` is null): say an install admin can turn on OneDrop's hosting accounts in
  Admin → Hosting, or an organization owner or admin can connect the organization's own in Settings → Organization
  → Hosting accounts; meanwhile Your domain or Tailscale publish it from the sandbox.

Tell the user which button or panel to use, in a sentence or two; they press it, not you.

## A host OneDrop doesn't deploy to

When they ask for a host that isn't on the list above (AWS, Heroku, Render, DigitalOcean, a VPS…):

1. Say plainly that OneDrop doesn't deploy there, and what it offers instead that covers the reason they asked
   (their own account and bill, a domain, staying up, scale).
2. Only if they still want it: keep the app portable (settings from the environment, as guides/hosting.md says) and
   write the config files that host needs (a Dockerfile, a CI workflow) for their own pipeline, and say they deploy
   it from there.

## Don't

- Don't run a provider's deploy command (`vercel deploy`, `fly deploy`, `wrangler deploy`, `netlify deploy`,
  `sam deploy`, `serverless deploy`, `railway up`, `eb deploy`, `git push heroku`…), sign in to a provider's CLI,
  or ask the user for a provider token so you can deploy. Publishing goes through the Publish button, which keeps
  provider keys out of this sandbox. The shell's CLIs are for the user.
- Don't tell the user to sign up somewhere, buy a server or set up hosting by hand when an option above covers it.
- Don't invent options, prices or limits that aren't here.
