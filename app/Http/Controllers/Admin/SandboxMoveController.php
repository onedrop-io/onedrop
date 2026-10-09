<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OldSandboxStatus;
use App\Enums\SandboxMovePhase;
use App\Http\Controllers\Controller;
use App\Models\SandboxMove;
use App\Sandbox\SandboxMover;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Moves of sandboxes to new ones, from Settings → Sandboxes (SBX-005, SBX-013): try a failed one again, restore files
 * recovered from a provider that had stopped answering, or dismiss them.
 */
class SandboxMoveController extends Controller
{
    /**
     * Try a failed move again.
     */
    public function retry(SandboxMove $move, SandboxMover $mover): RedirectResponse
    {
        abort_unless($move->phase === SandboxMovePhase::Failed, 409);

        $mover->start($move->sandbox, $move->reason, $move->keep_files, $move->reason === 'restore' ? $move->snapshot : null, array_diff_key($move->options ?? [], ['frozen' => true]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moving :project again.', ['project' => $move->project->name])]);

        return to_route('admin.sandboxes.index');
    }

    /**
     * Give the project a new sandbox with the files recovered from its old one, replacing what it has now.
     */
    public function restore(SandboxMove $move, SandboxMover $mover): RedirectResponse
    {
        abort_unless($move->old_status === OldSandboxStatus::Recovered && $move->recoveredSnapshot !== null, 409);

        $mover->start($move->sandbox, 'restore', from: $move->recoveredSnapshot);
        $move->update(['options' => [...($move->options ?? []), 'settled_at' => now()->toIso8601String()]]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restoring :project\'s recovered files.', ['project' => $move->project->name])]);

        return to_route('admin.sandboxes.index');
    }

    /**
     * Keep the project as it is: the recovered files are left to be deleted with its old snapshots.
     */
    public function dismiss(SandboxMove $move): RedirectResponse
    {
        $move->update(['options' => [...($move->options ?? []), 'settled_at' => now()->toIso8601String()]]);

        return to_route('admin.sandboxes.index');
    }
}
