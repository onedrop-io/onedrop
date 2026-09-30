<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('onedrop:admin {email : Email of an existing user}')]
#[Description('Make an existing user a site admin')]
class MakeAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error("No user with the email {$this->argument('email')}. Sign up first, then run this again.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->components->info("{$user->name} is now an admin.");

        return self::SUCCESS;
    }
}
