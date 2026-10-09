// Builds the project sandbox image as an E2B template (SBX-014): the published sandbox image (docker/sandbox, pushed to
// GitHub's registry by the `images` workflow) with start.sh started, then snapshotted by E2B, so new sandboxes start
// from it running. Run by `php artisan sandbox:build-image` (SANDBOX_PROVIDER=e2b) and the `e2b image` workflow.
//
// E2B_API_KEY, E2B_TEMPLATE (its name), E2B_SOURCE_IMAGE, E2B_CPU_COUNT, E2B_MEMORY_MB, E2B_DISK_MB (free space).
import { Template, defaultBuildLogger, waitForPort } from 'e2b';

const env = (name, fallback) => process.env[name] || fallback;

const template = Template()
    .fromImage(
        env('E2B_SOURCE_IMAGE', 'ghcr.io/onedrop-io/onedrop-sandbox:latest'),
    )
    .setUser('sandbox')
    .setWorkdir('/workspace')
    // start.sh serves a placeholder on PORT until the project's own settings arrive (written at create, then restart).
    .setStartCmd('/opt/onedrop/start.sh', waitForPort(8000));

const info = await Template.build(
    template,
    env('E2B_TEMPLATE', 'onedrop-sandbox'),
    {
        cpuCount: Number(env('E2B_CPU_COUNT', 2)),
        memoryMB: Number(env('E2B_MEMORY_MB', 4096)),
        minFreeDiskMb: Number(env('E2B_DISK_MB', 20000)),
        onBuildLogs: defaultBuildLogger(),
    },
);

console.log(
    `Built ${info.name}: template ${info.templateId}, build ${info.buildId}`,
);
