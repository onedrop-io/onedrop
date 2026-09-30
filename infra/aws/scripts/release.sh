#!/usr/bin/env bash
# Package the repo (committed + untracked, minus .gitignore'd files), upload it,
# and deploy it on the server if it's already installed.
#
#   cd infra/aws && npm run release
set -euo pipefail

cd "$(dirname "$0")/.."
BUCKET="$(pulumi stack output releaseBucket)"
INSTANCE="$(pulumi stack output instanceId)"
REPO="$(git rev-parse --show-toplevel)"
STAMP="$(date +%Y%m%d%H%M%S)"
mkdir -p .release
TARBALL="$PWD/.release/onedrop-$STAMP.tar.gz"

echo "==> Packaging $(git -C "$REPO" rev-parse --short HEAD) (+ uncommitted changes)"
(cd "$REPO" && git ls-files -co --exclude-standard -z | tar --null -czf "$TARBALL" -T -)

echo "==> Uploading to s3://$BUCKET"
aws s3 cp --only-show-errors "$TARBALL" "s3://$BUCKET/releases/onedrop-$STAMP.tar.gz"
aws s3 cp --only-show-errors "$TARBALL" "s3://$BUCKET/releases/latest.tar.gz"

if aws ssm describe-instance-information --filters "Key=InstanceIds,Values=$INSTANCE" \
    --query 'InstanceInformationList[0].PingStatus' --output text 2>/dev/null | grep -q Online; then
    echo "==> Deploying on $INSTANCE"
    # Refresh the server's deploy scripts from this release first, so new deploy steps take effect now.
    REMOTE="set -e
if [ ! -x /opt/onedrop/bin/deploy ]; then echo 'Still installing; the first release is picked up automatically.'; exit 0; fi
export AWS_DEFAULT_REGION=$(pulumi config get aws:region)
T=\$(mktemp --suffix=.tar.gz)
aws s3 cp --only-show-errors s3://$BUCKET/releases/onedrop-$STAMP.tar.gz \$T
for f in deploy set-domain; do tar -xzf \$T -O infra/server/\$f.sh > /opt/onedrop/bin/\$f.new; install -m 755 /opt/onedrop/bin/\$f.new /opt/onedrop/bin/\$f; rm /opt/onedrop/bin/\$f.new; done
/opt/onedrop/bin/deploy \$T
rm -f \$T"
    COMMAND_ID="$(aws ssm send-command --instance-ids "$INSTANCE" --document-name AWS-RunShellScript \
        --comment "onedrop deploy $STAMP" --timeout-seconds 3600 \
        --parameters "$(jq -n --arg script "$REMOTE" '{commands: [$script]}')" \
        --query 'Command.CommandId' --output text)"
    echo "    Follow it: aws ssm get-command-invocation --command-id $COMMAND_ID --instance-id $INSTANCE --query StandardOutputContent --output text"
else
    echo "==> Server not reachable through SSM yet; it installs the latest release on first boot."
fi
