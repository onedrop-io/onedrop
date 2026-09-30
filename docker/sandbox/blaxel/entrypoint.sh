#!/bin/sh
# Blaxel sandboxes: start the sandbox API, then start.sh through it (as the sandbox user, with the sandbox's env).
/usr/local/bin/sandbox-api --user sandbox &

until curl -fsS http://127.0.0.1:8080/health >/dev/null 2>&1; do
    sleep 0.1
done

# Blaxel sets PORT=80 in every process; the app's port arrives as ONEDROP_PORT.
curl -fsS -X POST http://127.0.0.1:8080/process -H 'Content-Type: application/json' \
    -d '{"name":"onedrop-start","command":"/opt/onedrop/start.sh","workingDir":"/workspace","env":{"PORT":"'"${ONEDROP_PORT:-8000}"'"}}' >/dev/null

wait
