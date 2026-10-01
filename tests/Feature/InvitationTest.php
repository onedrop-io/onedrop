<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Jeff']);
});

function registerThroughInvite(Invitation $invitation, string $email): TestResponse
{
    test()->get($invitation->url())->assertRedirect(route('register'));

    return test()->post(route('register.store'), [
        'name' => 'New Person',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

test('users can create an invite link, optionally for an email', function () {
    $this->actingAs($this->user)
        ->post(route('invitations.store', Organization::install()), ['email' => 'Sam@Example.com'])
        ->assertRedirect(route('invitations.index', Organization::install()));

    $invitation = Invitation::sole();

    expect($invitation->email)->toBe('sam@example.com')
        ->and($invitation->inviter->is($this->user))->toBeTrue()
        ->and($invitation->status())->toBe('waiting')
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($invitation->url())->toStartWith(url('/invite/'));
})->group('INV-001');

test('invite tokens are not stored in plain text', function () {
    $invitation = Invitation::issue($this->user);
    $token = str($invitation->url())->afterLast('/')->toString();

    $row = DB::table('invitations')->where('id', $invitation->id)->first();

    expect($row->token)->not->toContain($token)
        ->and($row->token_hash)->toBe(hash('sha256', $token));
})->group('INV-001');

test('the invites page lists links with status and copyable urls', function () {
    $waiting = Invitation::issue($this->user, 'a@example.com');
    Invitation::factory()->create(['invited_by' => $this->user->id, 'accepted_at' => now(), 'accepted_by' => User::factory()->create(['name' => 'Ana'])->id]);
    Invitation::factory()->create(['invited_by' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->get(route('invitations.index', Organization::install()))
        ->assertInertia(fn ($page) => $page
            ->component('invitations/index')
            ->has('invitations', 2)
            ->where('invitations.0.status', 'accepted')
            ->where('invitations.0.accepted_by', 'Ana')
            ->where('invitations.0.url', null)
            ->where('invitations.1.id', $waiting->id)
            ->where('invitations.1.url', $waiting->url()));
})->group('INV-001');

test('an invite link leads to sign-up with the email filled in', function () {
    $invitation = Invitation::issue($this->user, 'sam@example.com');

    $this->get($invitation->url())->assertRedirect(route('register'));

    $this->get(route('register'))
        ->assertInertia(fn ($page) => $page
            ->where('invitation.email', 'sam@example.com')
            ->where('invitation.invited_by', 'Jeff'));
})->group('INV-001');

test('signing up with the invited email accepts the invite and verifies the email', function () {
    $invitation = Invitation::issue($this->user, 'sam@example.com');

    registerThroughInvite($invitation, 'sam@example.com');

    $sam = User::where('email', 'sam@example.com')->sole();
    expect($invitation->fresh()->status())->toBe('accepted')
        ->and($invitation->fresh()->acceptedBy->is($sam))->toBeTrue()
        ->and($sam->email_verified_at)->not->toBeNull();
})->group('INV-001');

test('signing up with a different email accepts the invite but still needs verification', function () {
    $invitation = Invitation::issue($this->user, 'sam@example.com');

    registerThroughInvite($invitation, 'other@example.com');

    expect($invitation->fresh()->status())->toBe('accepted')
        ->and(User::where('email', 'other@example.com')->sole()->email_verified_at)->toBeNull();
})->group('INV-001');

test('an invite works only once', function () {
    $invitation = Invitation::issue($this->user);
    registerThroughInvite($invitation, 'first@example.com');
    auth()->logout();

    $this->get($invitation->url())
        ->assertStatus(410)
        ->assertInertia(fn ($page) => $page
            ->component('auth/invitation-invalid')
            ->where('reason', 'This invite has already been used.'));
})->group('INV-001');

test('expired, revoked and unknown links explain themselves', function (Closure $link, string $reason) {
    $this->get($link($this->user))
        ->assertStatus(410)
        ->assertInertia(fn ($page) => $page->where('reason', $reason));
})->with([
    'expired' => [fn ($user) => tap(Invitation::issue($user))->update(['expires_at' => now()->subMinute()])->url(), 'This invite has expired. Ask for a new one.'],
    'revoked' => [fn ($user) => tap(Invitation::issue($user))->update(['revoked_at' => now()])->url(), 'This invite was cancelled. Ask for a new one.'],
    'unknown' => [fn () => url('/invite/not-a-real-token'), "This invite link isn't valid. Check you copied all of it."],
])->group('INV-001');

test('signed-in users opening an invite go to the dashboard', function () {
    $invitation = Invitation::issue(User::factory()->create());

    $this->actingAs($this->user)->get($invitation->url())->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->status())->toBe('waiting');
})->group('INV-001');

test('registration without an invite still works', function () {
    $this->post(route('register.store'), [
        'name' => 'Walk In',
        'email' => 'walkin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'walkin@example.com')->exists())->toBeTrue();
})->group('INV-001');

test('users can revoke their own unused invites only', function () {
    $mine = Invitation::issue($this->user);
    $theirs = Invitation::issue(User::factory()->create());

    $this->actingAs($this->user)->delete(route('invitations.destroy', [$mine->organization, $mine]))->assertRedirect(route('invitations.index', Organization::install()));
    $this->actingAs($this->user)->delete(route('invitations.destroy', [$theirs->organization, $theirs]))->assertForbidden();

    expect($mine->fresh()->status())->toBe('revoked')
        ->and($theirs->fresh()->status())->toBe('waiting');
})->group('INV-001');

test('an invalid email is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('invitations.store', Organization::install()), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    expect(Invitation::count())->toBe(0);
})->group('INV-001');
