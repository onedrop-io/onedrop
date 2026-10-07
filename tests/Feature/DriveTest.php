<?php

use App\Enums\DriveSpaceKind;
use App\Enums\GroupRole;
use App\Enums\OrganizationRole;
use App\Events\DriveChanged;
use App\Models\AgentConnection;
use App\Models\DriveItem;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Sandbox\Drive\Drive;
use App\Sandbox\Drive\DriveException;
use App\Sandbox\Drive\DriveSpace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->organization = $this->user->currentOrganization();
    $this->drive = app(Drive::class);
    $this->org = new DriveSpace(DriveSpaceKind::Organization, $this->organization->id);
    $this->mine = new DriveSpace(DriveSpaceKind::Personal, $this->organization->id, userId: $this->user->id);
});

/** Someone else in the organization, with the role given. */
function driveColleague(Organization $organization, OrganizationRole $role = OrganizationRole::Member): User
{
    $user = User::factory()->has(AgentConnection::factory())->create();
    $organization->addMember($user);
    $organization->members()->updateExistingPivot($user->id, ['role' => $role->value]);
    $user->forgetOrganizationRoles();

    return $user;
}

/** A file in a place, through Drive's own upload. */
function driveFile(DriveSpace $space, string $name, string $contents, ?DriveItem $parent = null, ?User $actor = null): DriveItem
{
    $drive = app(Drive::class);
    $token = $drive->startUpload(Organization::findOrFail($space->organizationId), strlen($contents));

    if ($contents !== '') {
        $drive->receivePart($token, 0, $contents);
    }

    return $drive->finishUpload($token, $space, $parent, $name, $actor);
}

/** Upload through the page's API, as the browser does. */
function uploadThroughPage(object $test, Organization $organization, string $space, string $name, string $contents, ?int $parent = null)
{
    $upload = $test->postJson(route('drive.uploads.store', $organization), ['size' => strlen($contents)])->assertOk()->json('upload');

    if ($contents !== '') {
        $test->call('PUT', route('drive.uploads.part', [$organization, 0]), [], [], [], ['HTTP_X_ONEDROP_UPLOAD' => $upload, 'CONTENT_TYPE' => 'application/octet-stream'], $contents)->assertOk();
    }

    return $test->postJson(route('drive.files.store', [$organization, $space]), ['upload' => $upload, 'name' => $name, 'parent_id' => $parent]);
}

test('Drive opens on My Drive and lists the places the user may open', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Design']);
    $group->members()->attach($this->user, ['role' => GroupRole::Member->value]);
    Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Finance']);

    $this->actingAs($this->user)->get(route('drive.index', $this->organization))
        ->assertRedirect(route('drive.show', [$this->organization, 'personal']));

    $this->actingAs($this->user)->get(route('drive.show', [$this->organization, 'personal']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('drive/show')
            ->where('space.name', 'My Drive')
            ->where('spaces', [
                ['key' => 'personal', 'name' => 'My Drive', 'kind' => 'personal'],
                ['key' => 'organization', 'name' => $this->organization->name, 'kind' => 'organization'],
                ['key' => "group-{$group->id}", 'name' => 'Design', 'kind' => 'group'],
            ]));
})->group('DRIVE-001');

test("organization admins see every group's drive; members only their own groups'", function () {
    $finance = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Finance']);
    $admin = driveColleague($this->organization, OrganizationRole::Admin);

    $this->actingAs($admin)->get(route('drive.show', [$this->organization, "group-{$finance->id}"]))->assertOk();
    $this->actingAs($this->user)->get(route('drive.show', [$this->organization, "group-{$finance->id}"]))->assertNotFound();
})->group('DRIVE-001');

test('uploading a file in parts stores it where the user is, and lists it', function () {
    $contents = str_repeat('a', Drive::PART_BYTES + 10);
    $folder = $this->drive->createFolder($this->org, null, 'Reports', $this->user);

    $this->actingAs($this->user);
    $upload = $this->postJson(route('drive.uploads.store', $this->organization), ['size' => strlen($contents)])->json('upload');

    foreach ([0 => substr($contents, 0, Drive::PART_BYTES), 1 => substr($contents, Drive::PART_BYTES)] as $index => $part) {
        $this->call('PUT', route('drive.uploads.part', [$this->organization, $index]), [], [], [], ['HTTP_X_ONEDROP_UPLOAD' => $upload], $part)->assertOk();
    }

    $this->postJson(route('drive.files.store', [$this->organization, 'organization']), ['upload' => $upload, 'name' => 'q3.csv', 'parent_id' => $folder->id])
        ->assertOk()
        ->assertJson(['name' => 'q3.csv', 'size' => strlen($contents), 'folder' => false]);

    $file = DriveItem::query()->where('name', 'q3.csv')->firstOrFail();

    expect($file->parent_id)->toBe($folder->id)
        ->and($file->space)->toBe(DriveSpaceKind::Organization)
        ->and($file->updated_by)->toBe($this->user->id)
        ->and(Storage::disk('local')->get($file->blob))->toBe($contents);

    $this->get(route('drive.show', [$this->organization, 'organization', $folder->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('folder.name', 'Reports')
            ->has('items', 1)
            ->where('items.0.name', 'q3.csv')
            ->where('items.0.updated_by', $this->user->name));
})->group('DRIVE-001');

test('a part of the wrong size is refused', function () {
    $this->actingAs($this->user);
    $upload = $this->postJson(route('drive.uploads.store', $this->organization), ['size' => 10])->json('upload');

    $this->call('PUT', route('drive.uploads.part', [$this->organization, 0]), [], [], [], ['HTTP_X_ONEDROP_UPLOAD' => $upload], 'short')
        ->assertUnprocessable();
})->group('DRIVE-001');

test('uploading a file by a name already there replaces it with the new version', function () {
    $old = driveFile($this->org, 'notes.txt', 'v1');
    $oldBlob = $old->blob;

    $this->actingAs($this->user);
    uploadThroughPage($this, $this->organization, 'organization', 'notes.txt', 'v2')->assertOk()->assertJson(['id' => $old->id]);

    expect($this->drive->readText($old->fresh()))->toBe('v2')
        ->and(Storage::disk('local')->exists($oldBlob))->toBeFalse()
        ->and(DriveItem::query()->count())->toBe(1);
})->group('DRIVE-001');

test('uploads stop at the organization\'s limit', function () {
    config(['drive.quota_gb' => 10 / 1024 ** 3]);
    driveFile($this->org, 'big.bin', str_repeat('x', 8));

    $this->actingAs($this->user)
        ->postJson(route('drive.uploads.store', $this->organization), ['size' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Drive is full'));
})->group('DRIVE-001');

test('folders can be made, renamed and moved, never into themselves', function () {
    $this->actingAs($this->user);
    $a = $this->postJson(route('drive.folders.store', [$this->organization, 'organization']), ['name' => 'A'])->assertOk()->json('id');
    $b = $this->postJson(route('drive.folders.store', [$this->organization, 'organization']), ['name' => 'B', 'parent_id' => $a])->json('id');

    // A taken name gets a number.
    $this->postJson(route('drive.folders.store', [$this->organization, 'organization']), ['name' => 'A'])->assertJson(['name' => 'A (2)']);

    $this->patch(route('drive.items.update', [$this->organization, $b]), ['name' => 'Bee'])->assertRedirect();
    expect(DriveItem::find($b)->name)->toBe('Bee');

    $this->patch(route('drive.items.update', [$this->organization, $a]), ['space' => 'organization', 'parent_id' => $b])
        ->assertSessionHasErrors('drive');

    // Moving to My Drive takes what's inside with it.
    $file = driveFile($this->org, 'inside.txt', 'hi', DriveItem::find($b));
    $this->patch(route('drive.items.update', [$this->organization, $a]), ['space' => 'personal', 'parent_id' => null])->assertRedirect();

    expect(DriveItem::find($a)->space)->toBe(DriveSpaceKind::Personal)
        ->and(DriveItem::find($b)->user_id)->toBe($this->user->id)
        ->and($file->fresh()->space)->toBe(DriveSpaceKind::Personal);
})->group('DRIVE-001');

test('names with a slash, or "." and "..", are refused', function (string $name) {
    $this->actingAs($this->user)
        ->postJson(route('drive.folders.store', [$this->organization, 'organization']), ['name' => $name])
        ->assertUnprocessable();
})->with(['a/b', '.', '..'])->group('DRIVE-001');

test('files are downloaded, shown and edited as text', function () {
    $file = driveFile($this->org, 'readme.md', '# Hello');
    $this->actingAs($this->user);

    $this->get(route('drive.items.download', [$this->organization, $file]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="readme.md"; filename*=UTF-8\'\'readme.md')
        ->assertStreamedContent('# Hello');

    $this->get(route('drive.items.view', [$this->organization, $file]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->getJson(route('drive.items.text', [$this->organization, $file]))->assertJson(['text' => '# Hello', 'revision' => $file->revision]);

    $this->putJson(route('drive.items.text.update', [$this->organization, $file]), ['text' => '# Bye', 'revision' => $file->revision])->assertOk();
    expect($this->drive->readText($file->fresh()))->toBe('# Bye');

    // Saving over a change made since it was opened is refused.
    $this->putJson(route('drive.items.text.update', [$this->organization, $file]), ['text' => '# Stale', 'revision' => $file->revision])->assertUnprocessable();
})->group('DRIVE-001');

test('a folder downloads as a zip', function () {
    $folder = $this->drive->createFolder($this->org, null, 'Photos', $this->user);
    driveFile($this->org, 'a.txt', 'A', $folder);

    $this->actingAs($this->user)->get(route('drive.items.download', [$this->organization, $folder]))
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
})->group('DRIVE-001');

test("nobody opens another person's My Drive, a group they're not in, or another organization's drive", function () {
    $private = driveFile($this->mine, 'diary.txt', 'secret');
    $member = driveColleague($this->organization);
    $admin = driveColleague($this->organization, OrganizationRole::Admin);
    $outsider = User::factory()->has(AgentConnection::factory())->create();
    config(['app.multi_tenant' => true]);
    $elsewhere = Organization::createNamed('Elsewhere');
    $elsewhere->addMember($outsider, OrganizationRole::Owner);
    $theirs = driveFile(new DriveSpace(DriveSpaceKind::Organization, $elsewhere->id), 'plans.txt', 'x');

    foreach ([$member, $admin] as $other) {
        $this->actingAs($other)->get(route('drive.items.download', [$this->organization, $private]))->assertNotFound();
        $this->actingAs($other)->delete(route('drive.items.destroy', [$this->organization, $private]))->assertNotFound();
    }

    $this->actingAs($this->user)->get(route('drive.items.download', [$this->organization, $theirs]))->assertNotFound();
    $this->actingAs($this->user)->get(route('drive.items.download', [$elsewhere, $theirs]))->assertNotFound();
    expect($private->fresh()->trashed_at)->toBeNull();
})->group('DRIVE-001');

test('deleting moves to Trash, from where it can be restored or deleted for good', function () {
    $folder = $this->drive->createFolder($this->org, null, 'Old', $this->user);
    $file = driveFile($this->org, 'a.txt', 'A', $folder);
    $this->actingAs($this->user);

    $this->delete(route('drive.items.destroy', [$this->organization, $folder]))->assertRedirect();

    $this->get(route('drive.show', [$this->organization, 'organization']))->assertInertia(fn (Assert $page) => $page->has('items', 0));
    $this->get(route('drive.show', [$this->organization, 'organization', 'trash' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.name', 'Old')->where('items.0.trashed_by', $this->user->name));
    $this->get(route('drive.items.download', [$this->organization, $file]))->assertNotFound();

    // Restored beside a folder that took its name meanwhile.
    $this->drive->createFolder($this->org, null, 'Old', $this->user);
    $this->post(route('drive.items.restore', [$this->organization, $folder]))->assertRedirect();
    expect($folder->fresh()->name)->toBe('Old (2)')
        ->and($folder->fresh()->trashed_at)->toBeNull();

    $this->delete(route('drive.items.destroy', [$this->organization, $folder]));
    $blob = $file->blob;
    $this->delete(route('drive.items.purge', [$this->organization, $folder]))->assertRedirect();

    expect(DriveItem::find($folder->id))->toBeNull()
        ->and(DriveItem::find($file->id))->toBeNull()
        ->and(Storage::disk('local')->exists($blob))->toBeFalse();
})->group('DRIVE-002');

test('Trash is emptied after 30 days, and contents nothing points at go', function () {
    $old = driveFile($this->org, 'old.txt', 'A');
    $recent = driveFile($this->org, 'recent.txt', 'B');
    $this->drive->trash($old, $this->user);
    $this->drive->trash($recent, $this->user);
    $old->update(['trashed_at' => now()->subDays(31)]);
    Storage::disk('local')->put("drive/{$this->organization->id}/orphan", 'x');
    touch(Storage::disk('local')->path("drive/{$this->organization->id}/orphan"), now()->subDays(2)->getTimestamp());

    $this->artisan('drive:purge')->assertSuccessful();

    expect(DriveItem::find($old->id))->toBeNull()
        ->and(DriveItem::find($recent->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists("drive/{$this->organization->id}/orphan"))->toBeFalse()
        ->and(Storage::disk('local')->exists($recent->blob))->toBeTrue();
})->group('DRIVE-002');

test('a stale version is kept beside the newer one as a conflicted copy', function () {
    $file = driveFile($this->org, 'plan.txt', 'v1');
    $base = $file->revision;
    $this->drive->writeText($file, 'v2 from the web', $this->user);

    $token = $this->drive->startUpload($this->organization, 13);
    $this->drive->receivePart($token, 0, 'v2 from a box');
    $copy = $this->drive->finishUpload($token, $this->org, null, 'plan.txt', $this->user, replacing: $file->fresh(), baseRevision: $base);

    expect($copy->id)->not->toBe($file->id)
        ->and($copy->name)->toStartWith('plan (conflict ')
        ->and($this->drive->readText($file->fresh()))->toBe('v2 from the web');
})->group('DRIVE-003');

test('changes are told to open Drive pages of the place', function () {
    Event::fake([DriveChanged::class]);

    driveFile($this->mine, 'a.txt', 'A');

    Event::assertDispatched(DriveChanged::class, fn ($event) => $event->space === 'personal'
        && $event->userId === $this->user->id
        && $event->broadcastOn()[0]->name === "private-drive.{$this->organization->id}.user.{$this->user->id}");
})->group('DRIVE-001');

test('Drive refuses what it can\'t do with a reason', function () {
    expect(fn () => $this->drive->move($this->drive->createFolder($this->org, null, 'A', null), $this->org, null, 'a/b', null))
        ->toThrow(DriveException::class);
})->group('DRIVE-001');

test('a video plays from the part asked for, and PDFs show in the page', function () {
    $video = driveFile($this->org, 'demo.mp4', '0123456789');
    $pdf = driveFile($this->org, 'brief.pdf', '%PDF-1.4');
    $this->actingAs($this->user);

    $this->withHeaders(['Range' => 'bytes=2-4'])->get(route('drive.items.view', [$this->organization, $video]))
        ->assertStatus(206)
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Range', 'bytes 2-4/10');

    $this->flushHeaders();
    $page = $this->get(route('drive.items.view', [$this->organization, $pdf]))->assertOk();

    expect($page->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($page->headers->get('Content-Security-Policy'))->not->toContain('sandbox')
        ->and($this->get(route('drive.items.view', [$this->organization, $video]))->headers->get('Content-Security-Policy'))->toContain('sandbox');
})->group('DRIVE-001');
