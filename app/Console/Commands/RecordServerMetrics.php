<?php

namespace App\Console\Commands;

use App\Sandbox\ServerMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('server:sample')]
#[Description("Record a sample of the server's CPU, memory, disk and network use (ADMIN-003)")]
class RecordServerMetrics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ServerMetrics $metrics): int
    {
        $metrics->record();

        return self::SUCCESS;
    }
}
