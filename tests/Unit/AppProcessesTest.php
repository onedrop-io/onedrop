<?php

use App\Sandbox\AppProcesses;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A process's state letter (T: stopped).
 */
function processState(int $pid): string
{
    return substr(trim((string) shell_exec("ps -o stat= -p {$pid}")), 0, 1);
}

test('freezing stops start.sh and what it started, nothing else, and thawing lets them carry on', function () {
    // A stand-in for start.sh (named like it) with a child, and a process of the same user outside its tree.
    $app = proc_open(['bash', '-c', 'exec -a /opt/onedrop/start.sh bash -c "sleep 60 & wait"'], [], $pipes);
    $outside = proc_open(['sleep', '60'], [], $pipes);
    usleep(300_000);
    $root = (int) trim((string) shell_exec('pgrep -o -f "[/]opt/onedrop/start.sh"'));
    $child = (int) trim((string) shell_exec("pgrep -P {$root}"));
    $other = proc_get_status($outside)['pid'];

    try {
        expect($root)->toBeGreaterThan(0)->and($child)->toBeGreaterThan(0);

        Process::run(['bash', '-c', AppProcesses::FREEZE])->throw();

        expect(processState($root))->toBe('T')
            ->and(processState($child))->toBe('T')
            ->and(processState($other))->not->toBe('T');

        Process::run(['bash', '-c', AppProcesses::THAW])->throw();

        expect(processState($root))->not->toBe('T')
            ->and(processState($child))->not->toBe('T');
    } finally {
        foreach ([$child, $root] as $pid) {
            posix_kill($pid, SIGCONT);
            posix_kill($pid, SIGKILL);
        }
        proc_terminate($outside, SIGKILL);
        proc_close($app);
        proc_close($outside);
    }
})->group('SBX-002');

test('freezing a sandbox without start.sh does nothing', function () {
    expect(Process::run(['bash', '-c', AppProcesses::FREEZE])->successful())->toBeTrue();
})->skip(fn () => trim((string) shell_exec('pgrep -f "[/]opt/onedrop/start.sh"')) !== '', 'start.sh is running here')->group('SBX-002');
