<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/** The scripts after preview-frame.mjs's rewrite, run by Node. */
function withoutFrameChecks(array $scripts): array
{
    $module = base_path('docker/sandbox/preview-frame.mjs');
    $result = Process::input(json_encode($scripts))->run([
        'node', '--input-type=module', '-e',
        "import { withoutFrameChecks } from '{$module}';"
        ."let input = ''; process.stdin.on('data', (chunk) => input += chunk);"
        ."process.stdin.on('end', () => console.log(JSON.stringify(JSON.parse(input).map(withoutFrameChecks))));",
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true);
}

test("the preview rewrites an app's own frame checks to not in a frame", function () {
    expect(withoutFrameChecks([
        // NocoDB's route guard, as served.
        'var cl=C(async e=>{if(self!==top){throw Xt({statusCode:403,message:`Not allowed`})}});',
        'const isFramed = () => window.self !== window.top, next = 1',
        "const framed = window.top !== window.self\nstart()",
        'if (window === window.top) start()',
        'return top.location != self.location;',
        'mode = self !== top ? "embed" : "full"',
    ]))->toBe([
        'var cl=C(async e=>{if(false){throw Xt({statusCode:403,message:`Not allowed`})}});',
        'const isFramed = () => false, next = 1',
        "const framed = false\nstart()",
        'if (true) start()',
        'return false;',
        'mode = false ? "embed" : "full"',
    ]);
})->group('SBX-001');

test('the preview leaves lookalikes and talk with the parent frame alone', function () {
    $untouched = [
        'if (window.parent !== window) window.parent.postMessage(message, "*")',
        'const text = "self !== top"',
        'x = a + self !== top',
        'x = a == self !== top',
        'y = self !== top.frames',
        'z = self !== top?.frames',
        'myself !== top',
        'other.self !== top',
    ];

    expect(withoutFrameChecks($untouched))->toBe($untouched);
})->group('SBX-001');
