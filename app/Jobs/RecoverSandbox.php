<?php

namespace App\Jobs;

use App\Models\SandboxMove;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxWaitLimit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks on a sandbox a project moved off while it didn't answer (SBX-013): when an admin opens Settings → Sandboxes,
 * and when the snapshot of its newer files is done (FinishSnapshot). When it answers, its newer files are saved as a
 * recovered snapshot, and it's deleted.
 */
class RecoverSandbox implements ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

    public int $tries = 2;

    public function __construct(public SandboxMove $move) {}

    public function handle(SandboxMover $mover, SandboxWaitLimit $limit): void
    {
        // Provider calls end in time for a Flex queue job, even one run inside a request or another job (a sync queue).
        $limit->during(MoveProjectSandbox::WAIT_SECONDS, fn () => $this->run($mover));
    }

    protected function run(SandboxMover $mover): void
    {

        if ($move = $this->move->fresh()) {
            $mover->recover($move);
        }
    }
}
