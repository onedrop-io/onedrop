// The platform's Playwright config for the app's tests (see tests.mjs): the app's own config, if any, isn't used,
// so every app's tests run the same way, against the preview's dev server, with a video and a trace of each.
// With ONEDROP_BROWSER_CDP_PORT (browser.mjs, TEST-005) the browser opens that debugging port so the browser can
// attach to it once the test is stopped at a step, and nothing is recorded.
const workspace = process.env.ONEDROP_WORKSPACE || '/workspace';
const browserPort = process.env.ONEDROP_BROWSER_CDP_PORT;

export default {
    testDir: `${workspace}/tests/e2e`,
    outputDir:
        process.env.ONEDROP_TESTS_OUTPUT ||
        `${workspace}/.onedrop/tests/runs/manual`,
    // One at a time: the tests share the app's one database and dev server.
    workers: 1,
    retries: 0,
    // A test taken over in the Browser tab may be paused for as long as the user likes, then resumed (TEST-006):
    // its timeout's clock keeps running while it's paused, so it has none.
    timeout: browserPort ? 0 : 30_000,
    reporter: process.env.ONEDROP_TESTS_REPORT
        ? [['list'], ['json', { outputFile: process.env.ONEDROP_TESTS_REPORT }]]
        : [['list']],
    use: {
        baseURL: `http://127.0.0.1:${process.env.PORT || 8000}`,
        viewport: { width: 1280, height: 720 },
        video: browserPort
            ? 'off'
            : { mode: 'on', size: { width: 1280, height: 720 } },
        trace: browserPort ? 'off' : 'on',
        launchOptions: {
            executablePath: '/usr/bin/chromium',
            args: browserPort
                ? [
                      `--remote-debugging-port=${browserPort}`,
                      '--remote-debugging-address=127.0.0.1',
                  ]
                : [],
        },
    },
};
