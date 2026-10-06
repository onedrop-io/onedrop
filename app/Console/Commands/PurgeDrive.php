<?php

namespace App\Console\Commands;

use App\Sandbox\Drive\Drive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('drive:purge')]
#[Description('Delete for good what has been in Drive\'s Trash longer than it keeps things (DRIVE-002), and contents no file points at.')]
class PurgeDrive extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Drive $drive): int
    {
        $purged = $drive->purgeExpired();
        $collected = $drive->collectGarbage();

        // Only the latest revision is ever read (the next one is always higher).
        DB::table('drive_revisions')->where('id', '<', $drive->currentRevision())->delete();

        $this->components->info("Deleted {$purged} item(s) from Trash and {$collected} unused file(s).");

        return self::SUCCESS;
    }
}
