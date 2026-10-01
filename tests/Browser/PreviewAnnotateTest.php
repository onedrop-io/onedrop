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

    /** A preview page that answers the workspace the way the sandbox's annotator does, or with $error. */
    $this->preview = function (?string $error = null): void {
        $capture = $error === null
            ? "const c = document.createElement('canvas'); c.width = innerWidth; c.height = innerHeight;"
                ."const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);"
                ."parent.postMessage({onedrop: 'captured', id: d.id, image: c.toDataURL(), width: innerWidth, height: innerHeight, page: '/dashboard'}, '*');"
            : "parent.postMessage({onedrop: 'captured', id: d.id, error: '{$error}'}, '*');";
        $page = '<h1>Dashboard</h1><button id="save">Save changes</button><script>'
            ."addEventListener('message', (e) => { const d = e.data;"
            ."if (d.onedrop === 'capture') { {$capture} }"
            ."if (d.onedrop === 'inspect') parent.postMessage({onedrop: 'inspected', id: d.id, elements: d.marks.map(() => 'button#save (\"Save changes\")')}, '*');"
            .'});</script>';

        Sandbox::factory()->for($this->project)->create(['preview_url' => 'data:text/html,'.rawurlencode($page)]);
    };
});

/** Press, move and release the pointer on the picture, from and to points in its CSS pixels. */
function drawOnPicture(int $fromX, int $fromY, int $toX, int $toY): string
{
    return <<<JS
        () => {
            const canvas = document.querySelector('[data-test="annotate-canvas"]');
            const box = canvas.getBoundingClientRect();
            const at = (type, x, y) => canvas.dispatchEvent(new PointerEvent(type, {
                clientX: box.left + x, clientY: box.top + y, button: 0, pointerId: 1, bubbles: true,
            }));
            at('pointerdown', {$fromX}, {$fromY});
            at('pointermove', {$toX}, {$toY});
            at('pointerup', {$toX}, {$toY});
        }
        JS;
}

test('the user marks up the preview and sends it, and the agent is told what the marks point at', function () {
    ($this->preview)();

    $page = visit("/projects/{$this->project->id}")
        ->click('@preview-annotate')
        ->assertPresent('[data-test="annotate-canvas"][aria-busy="false"]')
        ->assertVisible('@preview-annotator')
        ->assertAttribute('@annotate-tool-text', 'aria-pressed', 'true')
        ->click('@annotate-tool-box');

    $page->script(drawOnPicture(20, 20, 140, 70));
    $page->assertEnabled('@annotate-undo')
        ->click('@annotate-color-blue')
        ->click('@annotate-tool-text');
    // A real click, so the browser moves focus as it would for the user.
    $page->click('@annotate-canvas')
        ->assertScript('document.activeElement?.dataset.test', 'annotate-note')
        ->type('@annotate-note', 'too big')
        ->keys('@annotate-note', 'Enter')
        ->assertMissing('@annotate-note')
        ->click('@annotate-done')
        ->assertMissing('@preview-annotator')
        ->assertVisible('form img[alt="preview-annotated.png"]')
        // The chat box only gets the picture; what the marks point at is sent for the agent alone.
        ->assertValue('#composer-content', '')
        ->type('#composer-content', 'Fix these');

    // Keep what the composer sends (Pest's browser server doesn't pass multipart uploads through yet).
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
        ->toContain("preview-annotated.png is the user's marked-up screenshot of the app's preview at /dashboard (")
        ->toContain('1. Box (red) around button#save ("Save changes")')
        ->toContain('2. Note "too big" on button#save ("Save changes")');
})->group('AGT-013');

test('a mark can be dragged into place, and undo puts it back', function () {
    ($this->preview)();
    // Whether the picture is red at a point, in its CSS pixels on screen.
    $redAt = fn (int $x, int $y) => <<<JS
        (() => {
            const canvas = document.querySelector('[data-test="annotate-canvas"]');
            const box = canvas.getBoundingClientRect();
            const [r, g] = canvas.getContext('2d').getImageData(
                Math.round({$x} * canvas.width / box.width), Math.round({$y} * canvas.height / box.height), 1, 1).data;
            return r > 200 && g < 120;
        })()
        JS;

    $page = visit("/projects/{$this->project->id}")->click('@preview-annotate')
        ->assertPresent('[data-test="annotate-canvas"][aria-busy="false"]')
        ->click('@annotate-tool-box');

    $page->script(drawOnPicture(20, 20, 140, 70));
    $page->assertScript($redAt(80, 20), true);

    // Pressing the box's top edge with the arrow tool picks the box up rather than drawing an arrow.
    $page->click('@annotate-tool-arrow');
    $page->script(drawOnPicture(80, 20, 80, 120));
    $page->assertScript($redAt(80, 20), false)
        ->assertScript($redAt(80, 120), true)
        ->click('@annotate-undo')
        ->assertScript($redAt(80, 20), true)
        ->assertScript($redAt(80, 120), false)
        ->assertNoJavaScriptErrors();
})->group('AGT-013');

test('marks can be undone and cleared, and cancelling leaves the chat as it was', function () {
    ($this->preview)();

    $page = visit("/projects/{$this->project->id}")
        ->click('@preview-annotate')
        ->assertPresent('[data-test="annotate-canvas"][aria-busy="false"]')
        ->assertDisabled('@annotate-undo')
        ->click('@annotate-tool-box');

    $page->script(drawOnPicture(20, 20, 140, 70));
    $page->script(drawOnPicture(30, 90, 200, 160));
    $page->click('@annotate-undo')
        ->assertEnabled('@annotate-clear')
        ->click('@annotate-clear')
        ->assertDisabled('@annotate-clear')
        // Clearing can be undone too.
        ->click('@annotate-undo')
        ->assertEnabled('@annotate-clear')
        ->click('@annotate-cancel')
        ->assertMissing('@preview-annotator')
        ->assertMissing('form [data-test="attachment"]')
        ->assertNoJavaScriptErrors();
})->group('AGT-013');

test('a preview that cannot take its own picture offers sharing the tab instead', function () {
    ($this->preview)('Capture failed');

    visit("/projects/{$this->project->id}")
        ->click('@preview-annotate')
        ->assertSeeIn('@annotate-error', "The preview couldn't take a picture of itself.")
        ->assertVisible('@annotate-share-tab')
        ->assertMissing('@preview-annotator')
        ->click('[data-test="annotate-error"] button[aria-label="Dismiss"]')
        ->assertMissing('@annotate-error')
        ->assertNoJavaScriptErrors();
})->group('AGT-013');
