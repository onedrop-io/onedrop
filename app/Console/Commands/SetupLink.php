<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('onedrop:setup-link')]
#[Description('Print the link that creates the first account on a server install (fails once it exists)')]
class SetupLink extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = config('auth.setup_token');

        if (! is_string($token) || $token === '' || User::query()->exists()) {
            return self::FAILURE;
        }

        $this->line(route('register', ['setup' => $token]));

        return self::SUCCESS;
    }
}
