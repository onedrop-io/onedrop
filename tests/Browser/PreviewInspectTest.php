<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->actingAs($this->user);

    // A preview page running the sandbox's real inspector. Save looks like a React component's button, and the page
    // takes its own picture the way the sandbox's annotator does.
    $page = '<!doctype html><html><head><style>body{margin:0;font:16px sans-serif} nav{display:flex;gap:16px;padding:16px}'
        .' button{width:160px;height:48px}</style></head><body><h1>Dashboard</h1>'
        .'<nav><button id="save" class="btn">Save</button><button id="cancel" class="btn">Cancel</button></nav>'
        .'<script>'
        ."window.clicks = 0; document.getElementById('save').addEventListener('click', () => window.clicks++);"
        ."document.getElementById('save').__reactFiber\$test = { _debugOwner: { type: function SaveButton() {} },"
        ." _debugSource: { fileName: '/workspace/resources/js/pages/dashboard.tsx', lineNumber: 12 } };"
        ."addEventListener('message', (e) => { const d = e.data; if (d.onedrop !== 'capture') return;"
        ." const c = document.createElement('canvas'); c.width = innerWidth; c.height = innerHeight;"
        ." const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);"
        ." parent.postMessage({onedrop: 'captured', id: d.id, image: c.toDataURL(), width: innerWidth, height: innerHeight, page: '/dashboard'}, '*'); });"
        .'</script><script>'.file_get_contents(base_path('docker/sandbox/inspector.js')).'</script></body></html>';

    Sandbox::factory()->for($this->project)->create(['preview_url' => 'data:text/html,'.rawurlencode($page)]);
});

/** The page's buttons that are showing, in order. */
const VISIBLE_BUTTONS = "() => Array.from(document.querySelectorAll('nav button')).filter((b) => b.offsetParent).map((b) => b.textContent).join(',')";

test('the user picks and swaps elements in the live preview and sends them to the agent', function () {
    $page = visit("/projects/{$this->project->id}")
        ->click('@preview-inspect')
        ->assertAttribute('@preview-inspect', 'aria-pressed', 'true')
        ->assertVisible('@preview-inspector');

    // A click picks the element instead of reaching the app.
    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->click('#save')
            ->assertScript('window.clicks', 0);
    });
    $page->assertCount('@inspector-item', 1)
        ->assertSeeIn('@preview-inspector', '#save · SaveButton')
        ->type('@inspector-note', 'make it green');

    // Dragging one button onto the middle of the other swaps them on the page.
    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->drag('#cancel', '#save')
            ->assertScript(VISIBLE_BUTTONS, 'Cancel,Save');
    });
    $page->assertCount('@inspector-item', 2)
        ->assertSeeIn('@preview-inspector', '#cancel swapped with #save · SaveButton');

    // Keep what the composer sends (Pest's browser server doesn't pass multipart uploads through yet).
    $page->click('@inspector-done')
        ->assertMissing('@preview-inspector')
        ->assertAttribute('@preview-inspect', 'aria-pressed', 'false')
        ->assertVisible('form img[alt="preview-inspected.png"]')
        ->assertValue('#composer-content', '');
    $page->script(<<<'JS'
        () => {
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if (body instanceof FormData) window.sentContext = body.get('agent_context');
                return send.apply(this, arguments);
            };
        }
        JS);
    $page->click('@composer-send')
        ->assertScript('typeof window.sentContext', 'string')
        ->assertNoJavaScriptErrors();

    expect($page->script('() => window.sentContext'))
        ->toContain("preview-inspected.png is a screenshot of the app's preview at /dashboard (")
        ->toContain('1. #save ("Save"), <SaveButton> in resources/js/pages/dashboard.tsx:12. The user says: "make it green"')
        ->toContain('2. #cancel ("Cancel"). Swap it with #save ("Save"), <SaveButton> in resources/js/pages/dashboard.tsx:12.');

    // Stopping put the page back as it was.
    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->assertScript(VISIBLE_BUTTONS, 'Save,Cancel')
            ->assertScript("document.querySelector('onedrop-inspector')", null);
    });
})->group('AGT-014');

test('removing a move puts the page back, and cancelling stops inspecting', function () {
    $page = visit("/projects/{$this->project->id}")->click('@preview-inspect');

    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->drag('#cancel', '#save')
            ->assertScript(VISIBLE_BUTTONS, 'Cancel,Save');
    });
    $page->assertCount('@inspector-item', 1)
        ->click('@inspector-remove')
        ->assertCount('@inspector-item', 0);
    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->assertScript(VISIBLE_BUTTONS, 'Save,Cancel');
    });

    $page->click('@inspector-cancel')
        ->assertMissing('@preview-inspector')
        ->assertMissing('form [data-test="attachment"]')
        ->assertNoJavaScriptErrors();
    $page->withinFrame('[data-test="preview-frame"]', function ($frame) {
        $frame->click('#save')
            ->assertScript('window.clicks', 1);
    });
})->group('AGT-014');

test('picking an element\'s parent, and Esc in the page stops inspecting', function () {
    $page = visit("/projects/{$this->project->id}")->click('@preview-inspect');

    $page->withinFrame('[data-test="preview-frame"]', fn ($frame) => $frame->click('#save'));
    $page->click('@inspector-parent')
        ->assertSeeIn('@preview-inspector', 'nav');
    $page->withinFrame('[data-test="preview-frame"]', fn ($frame) => $frame->script("() => dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))"));
    $page->assertMissing('@preview-inspector')
        ->assertAttribute('@preview-inspect', 'aria-pressed', 'false')
        ->assertNoJavaScriptErrors();
})->group('AGT-014');
