import * as aws from '@pulumi/aws';
import * as pulumi from '@pulumi/pulumi';

/**
 * One EC2 server running the whole app builder: Laravel, the queue worker,
 * Caddy (HTTPS) and Docker sandboxes. Releases are uploaded to S3 by
 * scripts/release.sh; the server installs itself from the first one.
 */
const config = new pulumi.Config('zap');
const instanceType = config.get('instanceType') ?? 't4g.large';
const diskGb = config.getNumber('diskGb') ?? 100;
// Leave unset to use <elastic-ip>.sslip.io, which needs no DNS setup.
const customDomain = config.get('domain');

const name = `zap-${pulumi.getStack()}`;
const tags = { Project: 'zap', Stack: pulumi.getStack() };

// Ubuntu 24.04 LTS, looked up from Canonical's public parameter.
const arch = instanceType.match(/^[a-z]+\d+g/) ? 'arm64' : 'amd64';
const ami = aws.ssm.getParameterOutput({
    name: `/aws/service/canonical/ubuntu/server/24.04/stable/current/${arch}/hvm/ebs-gp3/ami-id`,
});

// Private bucket for release tarballs.
const releases = new aws.s3.Bucket(`${name}-releases`, {
    forceDestroy: true,
    tags,
});
new aws.s3.BucketPublicAccessBlock(`${name}-releases`, {
    bucket: releases.id,
    blockPublicAcls: true,
    blockPublicPolicy: true,
    ignorePublicAcls: true,
    restrictPublicBuckets: true,
});

// The server can read releases and be managed through SSM (no SSH port, no key pair).
const role = new aws.iam.Role(`${name}-server`, {
    assumeRolePolicy: aws.iam.assumeRolePolicyForPrincipal({
        Service: 'ec2.amazonaws.com',
    }),
    tags,
});
new aws.iam.RolePolicyAttachment(`${name}-ssm`, {
    role: role.name,
    policyArn: 'arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore',
});
new aws.iam.RolePolicy(`${name}-releases`, {
    role: role.id,
    policy: releases.arn.apply((arn) =>
        JSON.stringify({
            Version: '2012-10-17',
            Statement: [
                {
                    Effect: 'Allow',
                    Action: ['s3:GetObject'],
                    Resource: `${arn}/releases/*`,
                },
                { Effect: 'Allow', Action: ['s3:ListBucket'], Resource: arn },
            ],
        }),
    ),
});
const profile = new aws.iam.InstanceProfile(`${name}-server`, {
    role: role.name,
    tags,
});

// Only HTTPS (and HTTP for certificate issuance / redirects) from the internet.
const firewall = new aws.ec2.SecurityGroup(`${name}-web`, {
    description: 'Zap: HTTP and HTTPS in, everything out',
    ingress: [
        {
            protocol: 'tcp',
            fromPort: 80,
            toPort: 80,
            cidrBlocks: ['0.0.0.0/0'],
            ipv6CidrBlocks: ['::/0'],
        },
        {
            protocol: 'tcp',
            fromPort: 443,
            toPort: 443,
            cidrBlocks: ['0.0.0.0/0'],
            ipv6CidrBlocks: ['::/0'],
        },
    ],
    egress: [
        {
            protocol: '-1',
            fromPort: 0,
            toPort: 0,
            cidrBlocks: ['0.0.0.0/0'],
            ipv6CidrBlocks: ['::/0'],
        },
    ],
    tags,
});

// Allocate the public IP first so the domain is known before the server boots.
const ip = new aws.ec2.Eip(`${name}-ip`, { domain: 'vpc', tags });
const domain = customDomain
    ? pulumi.output(customDomain)
    : ip.publicIp.apply((address) => `${address.replace(/\./g, '-')}.sslip.io`);
const releaseUrl = pulumi.interpolate`s3://${releases.bucket}/releases/latest.tar.gz`;

const userData = pulumi.interpolate`#!/bin/bash
set -euo pipefail
exec > >(tee -a /var/log/zap-bootstrap.log) 2>&1
export AWS_DEFAULT_REGION=${aws.getRegionOutput().name}
snap install aws-cli --classic
echo "export AWS_DEFAULT_REGION=$AWS_DEFAULT_REGION" > /etc/profile.d/zap-aws.sh
echo "Waiting for the first release at ${releaseUrl} (upload it with: npm run release)"
until aws s3 ls "${releaseUrl}" >/dev/null 2>&1; do sleep 15; done
mkdir -p /tmp/zap-release
aws s3 cp "${releaseUrl}" /tmp/zap-release.tar.gz
tar -xzf /tmp/zap-release.tar.gz -C /tmp/zap-release infra/server
APP_DOMAIN="${domain}" APP_RELEASE="${releaseUrl}" bash /tmp/zap-release/infra/server/bootstrap.sh
`;

const server = new aws.ec2.Instance(
    `${name}-server`,
    {
        ami: ami.value,
        instanceType,
        iamInstanceProfile: profile.name,
        vpcSecurityGroupIds: [firewall.id],
        userData,
        userDataReplaceOnChange: false,
        rootBlockDevice: {
            volumeSize: diskGb,
            volumeType: 'gp3',
            encrypted: true,
        },
        metadataOptions: { httpTokens: 'required', httpEndpoint: 'enabled' },
        tags: { ...tags, Name: name },
    },
    {
        // The bootstrap script only runs on first boot; changing it (e.g. a new domain) must not
        // stop the instance. Switch domains on the server with /opt/zap/bin/set-domain instead.
        ignoreChanges: ['userData'],
    },
);

new aws.ec2.EipAssociation(`${name}-ip`, {
    instanceId: server.id,
    allocationId: ip.id,
});

export const url = pulumi.interpolate`https://${domain}`;
export const zapDomain = domain;
export const publicIp = ip.publicIp;
export const instanceId = server.id;
export const releaseBucket = releases.bucket;
export const shell = pulumi.interpolate`aws ssm start-session --target ${server.id}`;
