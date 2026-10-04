# Serverless apps

Follow this when the user asks for serverless functions (Cloudflare Workers or Pages, AWS Lambda, Vercel,
Netlify, Google Cloud or Azure Functions), or when /workspace has a `wrangler.toml`/`wrangler.jsonc`,
`template.yaml` (SAM), `vercel.json`, `netlify.toml` or `serverless.yml`.

Functions don't run as a server, so the preview runs the platform's local emulator: `.onedrop/dev` starts it
on 0.0.0.0:$PORT like any other dev server. Everything runs inside the sandbox:

- Never sign in to the user's cloud account (`wrangler login`, `vercel login`, `aws configure`, `sst dev`)
  and never use real cloud keys. Only use a token the user added in Tools → Secrets for the one tool below
  that needs it.
- Don't deploy (`wrangler deploy`, `sam deploy`, `vercel deploy`, `serverless deploy`). OneDrop's Publish
  button publishes what the preview runs. If the user asks to deploy to their own account, say it isn't
  done from OneDrop yet, and keep the project's config ready for their own pipeline.
- Install JavaScript CLIs as the app's dev dependencies (`npm install -D wrangler@4`), not globally, so the app
  keeps its version. The shell's own `wrangler`, `vercel`, `aws` and the like are for the user.

## Cloudflare Workers and Pages

```bash
#!/usr/bin/env bash
export WRANGLER_SEND_METRICS=false
exec npx wrangler dev --ip 0.0.0.0 --port "$PORT" --show-interactive-dev-session=false
```

- Wrangler runs Cloudflare's own runtime (workerd), so this is what production runs. D1, KV, R2, Durable
  Objects and Queues bound in the Wrangler config are emulated, with their data in `.wrangler/state`.
- A Pages project serves its build folder: `npx wrangler pages dev ./dist --ip 0.0.0.0 --port "$PORT"`.
- A project that uses `@cloudflare/vite-plugin` runs Vite instead, which runs the Worker in workerd too:
  `npx vite --host 0.0.0.0 --port "$PORT"`, with `server: { allowedHosts: true }` in vite.config.
- Secrets stay in /workspace/.env (Tools → Secrets); Wrangler 4 reads it when there's no `.dev.vars`, so
  don't create one.

## AWS Lambda with SAM

SAM runs each function in a Lambda container, so it needs Docker inside the sandbox. If `docker info` fails,
tell the user to turn on Docker inside sandboxes in Settings → Sandboxes, and wait.

`sam` is on PATH; it installs itself the first time it runs (a minute at most).

```bash
#!/usr/bin/env bash
export SAM_CLI_TELEMETRY=0
# Fake credentials: SAM passes them to the functions, and the local stand-ins accept any.
export AWS_ACCESS_KEY_ID=local AWS_SECRET_ACCESS_KEY=local AWS_REGION=us-east-1
docker compose -f .onedrop/aws.compose.yaml up -d --wait
node scripts/setup-local.mjs
sam build --cached
exec sam local start-api --host 0.0.0.0 --port "$PORT" \
    --docker-network onedrop-aws --env-vars .onedrop/sam-env.json --warm-containers EAGER
```

- Leave out the compose and setup lines if the functions use no other AWS services, and `sam build` for
  plain JavaScript or Python whose `CodeUri` is the source folder. After changing code that needs a build,
  run `/opt/onedrop/restart`.
- With a front end, serve it on $PORT and run SAM on another port (e.g. 3001), with API paths under `/api`
  in `template.yaml` and `{"/api": 3001}` in /workspace/.onedrop/routes.json.

### AWS services the functions use

Run local stand-ins in the sandbox's Docker, never real AWS. `.onedrop/aws.compose.yaml` (keep only what
the app uses):

```yaml
name: onedrop-aws
services:
    dynamodb:
        image: amazon/dynamodb-local
        command: -jar DynamoDBLocal.jar -sharedDb -dbPath /data
        user: root
        volumes: [./data/dynamodb:/data]
        ports: ['127.0.0.1:8001:8000']
    s3:
        image: adobe/s3mock
        ports: ['127.0.0.1:9090:9090']
    sqs:
        image: softwaremill/elasticmq-native
        ports: ['127.0.0.1:9324:9324']
networks:
    default:
        name: onedrop-aws
```

Point the AWS SDK at them with its standard endpoint settings, so the code has nothing that only works
locally. Functions reach the stand-ins by service name, in `.onedrop/sam-env.json`:

```json
{
    "Parameters": {
        "AWS_ENDPOINT_URL_DYNAMODB": "http://dynamodb:8000",
        "AWS_ENDPOINT_URL_S3": "http://s3:9090",
        "AWS_ENDPOINT_URL_SQS": "http://sqs:9324"
    }
}
```

SAM only passes variables the template declares, so list them under `Globals: Function: Environment:
Variables:` in `template.yaml` with empty values. For S3, set `forcePathStyle: true` on the client when
`AWS_ENDPOINT_URL_S3` is set. `scripts/setup-local.mjs` runs in the sandbox, not in a container, so it uses
the published ports (`http://localhost:8001`, `:9090`, `:9324`). It retries until they answer (they take a
few seconds to start), creates the app's tables, buckets and queues if they're missing, then adds sample
data. DynamoDB keeps its data in `.onedrop/data`; the S3 and SQS stand-ins start empty each time, so the
script makes their buckets and queues again.

LocalStack emulates more of AWS in one container, but it needs a LocalStack account: only use it if the user
adds `LOCALSTACK_AUTH_TOKEN` in Tools → Secrets (its free plan is for non-commercial use, so tell them).

## Vercel

Run the framework's own dev server, not `vercel dev`, which needs a Vercel sign-in. Its functions are the
framework's routes: `npx next dev -H 0.0.0.0 -p "$PORT"` for Next.js (route handlers, server actions), and
SvelteKit, Nuxt, Astro and Remix run their usual dev command. Rewrites and headers in `vercel.json` only
apply on Vercel: move the ones the app needs into the framework's config.

A bare `api/` folder with no framework has no dev server of its own. Tell the user, and suggest moving the
functions into the app's framework. Only if they'd rather keep it, ask them to add `VERCEL_TOKEN` in
Tools → Secrets, and say that the first run links the folder to a project in their Vercel account:
`exec npx vercel dev --listen "0.0.0.0:$PORT" --token "$VERCEL_TOKEN" --yes`.

## Others

Check each tool's `--help` for how to listen on 0.0.0.0:$PORT.

| Project                                 | Run                                                                                                                                                           |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Netlify (`netlify.toml`)                | `npx netlify-cli dev --offline`                                                                                                                               |
| Serverless Framework (`serverless.yml`) | `serverless@3` with the `serverless-offline` plugin: `npx serverless offline --host 0.0.0.0 --httpPort "$PORT"`. Version 4 needs a sign-in for every command. |
| Google Cloud Functions                  | `npx @google-cloud/functions-framework --target=<function> --port="$PORT"`                                                                                    |
| Azure Functions                         | `npx azure-functions-core-tools@4 start --port "$PORT"`                                                                                                       |
| Architect (`app.arc`)                   | `npx arc sandbox`                                                                                                                                             |
| Supabase Edge Functions                 | `npx supabase start` and `npx supabase functions serve` (needs Docker inside sandboxes)                                                                       |
| SST                                     | Needs a real AWS account even for `sst dev`: tell the user, and offer SAM instead.                                                                            |
| Firebase emulators                      | Need Java, which the sandbox doesn't have: tell the user.                                                                                                     |

## Finishing

Call each function through the preview (`curl -sf "localhost:$PORT/<path>"`), check
/workspace/.onedrop/errors.log, and tell the user in one or two sentences what works in the preview.
