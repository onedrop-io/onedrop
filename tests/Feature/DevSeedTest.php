<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('the seeder creates a known admin dev user', function () {
    $this->seed(DatabaseSeeder::class);

    $dev = User::where('email', 'dev@example.com')->firstOrFail();

    expect(Hash::check('password', $dev->password))->toBeTrue()
        ->and($dev->is_admin)->toBeTrue()
        ->and($dev->email_verified_at)->not->toBeNull()
        ->and($dev->groups()->count())->toBe(1);
})->group('AUTH-001');

test('the seeder creates nothing outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    app(DatabaseSeeder::class)->__invoke();

    expect(User::count())->toBe(0);
})->group('AUTH-001');
