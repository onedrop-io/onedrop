<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake('local');
});

test('admins can rename the app and go back to the default name', function () {
    $admin = User::factory()->admin()->create();
    $default = config('app.name');

    $this->actingAs($admin)->patch(route('admin.general.update'), ['name' => 'Acme Builder'])
        ->assertRedirect(route('admin.general.edit'));

    expect(config('app.name'))->toBe('Acme Builder');
    $this->actingAs($admin)->get(route('admin.general.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/general')
            ->where('name', 'Acme Builder')
            ->where('customName', 'Acme Builder')
            ->where('defaultName', $default));

    $this->actingAs($admin)->patch(route('admin.general.update'), ['name' => '']);

    expect(config('app.name'))->toBe($default);
})->group('ADMIN-001');

test('admins can upload a logo that every page and the sign-in page use, and remove it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.general.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])
        ->assertRedirect(route('admin.general.edit'));

    $logo = null;
    $this->actingAs($admin)->get(route('admin.general.edit'))
        ->assertInertia(function (AssertableInertia $page) use (&$logo) {
            $logo = $page->toArray()['props']['logo'];
        });

    expect($logo)->toStartWith('/branding/logo?v=');

    auth()->logout();
    $this->get($logo)->assertOk()->assertHeader('Content-Security-Policy');
    $this->get(route('login'))->assertSee($logo, false);

    $this->actingAs($admin)->delete(route('admin.general.logo.destroy'));

    $this->get(route('branding.logo'))->assertNotFound();
    expect(Storage::disk('local')->allFiles('branding'))->toBe([]);
})->group('ADMIN-001');

test('logos must be small images', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.general.logo.store'), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('logo');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.general.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png')->size(2048)])
        ->assertSessionHasErrors('logo');
})->group('ADMIN-001');

test('non-admins cannot change the name or logo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.general.edit'))->assertForbidden();
    $this->actingAs($user)->patch(route('admin.general.update'), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($user)->post(route('admin.general.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();

    expect(config('app.name'))->not->toBe('Mine');
})->group('ADMIN-001');
