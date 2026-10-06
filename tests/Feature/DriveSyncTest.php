<?php

use App\Enums\DriveSpaceKind;
use App\Enums\GroupRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectKind;
use App\Models\AgentConnection;
use App\Models\DriveItem;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Drive\Drive;
use App\Sandbox\Drive\DriveSpace;
use App\Sandbox\Drive\DriveSync;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->organization = $this->user->currentOrganization();
    $this->drive = app(Drive::class);
    $this->org = new DriveSpace(DriveSpaceKind::Organization, $this->organization->id);
    $this->mine = new DriveSpace(DriveSpaceKind::Personal, $this->organization->id, userId: $this->user->id);

    $this->group = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Design/Brand']);
    $this->group->members()->attach($this->user, ['role' => GroupRole::Member->value]);

    $this->project = Project::factory()->for($this->user)->create(['organization_id' => $this->organization->id]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create();
    $this->computer = Project::factory()->for($this->user)->create(['organization_id' => $this->organization->id, 'kind' => ProjectKind::Computer]);
    $this->computerSandbox = Sandbox::factory()->for($this->computer)->create();
});

/** A request from a sandbox's Drive daemon, with its token. */
function fromSandbox(object $test, Sandbox $sandbox): object
{
    return $test->withToken(DriveSync::token($sandbox));
}

/** A file in a place, through Drive's own upload. */
function syncFile(DriveSpace $space, string $name, string $contents, ?DriveItem $parent = null): DriveItem
{
    $drive = app(Drive::class);
    $token = $drive->startUpload(Organization::findOrFail($space->organizationId), strlen($contents));
    $drive->receivePart($token, 0, $contents);

    return $drive->finishUpload($token, $space, $parent, $name, null);
}

test('a sandbox needs its own token', function () {
    $this->getJson(route('drive-sync.state', $this->sandbox))->assertForbidden();
    $this->withToken(DriveSync::token($this->computerSandbox))->getJson(route('drive-sync.state', $this->sandbox))->assertForbidden();
    fromSandbox($this, $this->sandbox)->getJson(route('drive-sync.state', $this->sandbox))->assertOk();
})->group('DRIVE-003', 'DRIVE-004');

test('a computer keeps My Drive and the shared drives; a project only the shared ones', function () {
    fromSandbox($this, $this->computerSandbox)->getJson(route('drive-sync.state', $this->computerSandbox))
        ->assertJsonPath('places', [
            ['key' => 'personal', 'dir' => 'My Drive'],
            ['key' => 'organization', 'dir' => $this->organization->name],
            ['key' => "group-{$this->group->id}", 'dir' => 'Groups/Design-Brand'],
        ]);

    fromSandbox($this, $this->sandbox)->getJson(route('drive-sync.state', $this->sandbox))
        ->assertJsonPath('places', [
            ['key' => 'organization', 'dir' => $this->organization->name],
            ['key' => "group-{$this->group->id}", 'dir' => 'Groups/Design-Brand'],
        ]);
})->group('DRIVE-003', 'DRIVE-004');

test("a project's sandbox can't reach its owner's My Drive", function () {
    $private = syncFile($this->mine, 'diary.txt', 'secret');

    fromSandbox($this, $this->sandbox)->getJson(route('drive-sync.state', [$this->sandbox, 'after' => 0]))
        ->assertJsonMissing(['name' => 'diary.txt']);
    fromSandbox($this, $this->sandbox)->get(route('drive-sync.items.content', [$this->sandbox, $private->id]))->assertNotFound();
    fromSandbox($this, $this->sandbox)->deleteJson(route('drive-sync.items.destroy', [$this->sandbox, $private->id]))->assertOk();

    expect($private->fresh()->trashed_at)->toBeNull();
})->group('DRIVE-004');

test('the feed gives what changed after a revision, and a place in full', function () {
    $first = syncFile($this->org, 'a.txt', 'A');
    $cursor = $first->revision;
    $second = syncFile($this->org, 'b.txt', 'B');
    $this->drive->trash($first, null);

    $feed = fromSandbox($this, $this->sandbox)->getJson(route('drive-sync.state', [$this->sandbox, 'after' => $cursor]))->assertOk()->json();

    expect(collect($feed['items'])->pluck('name')->all())->toBe(['b.txt', 'a.txt'])
        ->and(collect($feed['items'])->firstWhere('name', 'a.txt')['trashed'])->toBeTrue()
        ->and($feed['cursor'])->toBe($this->drive->currentRevision());

    $full = fromSandbox($this, $this->sandbox)->getJson(route('drive-sync.state', [$this->sandbox, 'space' => 'organization']))->json();
    expect(collect($full['items'])->pluck('id')->all())->toBe([$second->id]);
})->group('DRIVE-003');

test('a sandbox uploads, renames, moves and deletes in Drive', function () {
    $sandbox = fromSandbox($this, $this->computerSandbox);
    $folder = $sandbox->postJson(route('drive-sync.folders', $this->computerSandbox), ['space' => 'personal', 'name' => 'Trips'])->assertOk()->json();

    // A folder already there is used, not doubled.
    $sandbox->postJson(route('drive-sync.folders', $this->computerSandbox), ['space' => 'personal', 'name' => 'Trips'])->assertJson(['id' => $folder['id']]);

    $upload = $sandbox->postJson(route('drive-sync.uploads', $this->computerSandbox), ['size' => 5])->assertJson(['part_bytes' => Drive::PART_BYTES])->json('upload');
    $this->call('PUT', route('drive-sync.uploads.part', [$this->computerSandbox, 0]), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.DriveSync::token($this->computerSandbox),
        'HTTP_X_ONEDROP_UPLOAD' => $upload,
    ], 'hello')->assertOk();
    $file = $sandbox->postJson(route('drive-sync.files', $this->computerSandbox), [
        'upload' => $upload, 'space' => 'personal', 'parent_id' => $folder['id'], 'name' => 'lisbon.csv', 'sha256' => hash('sha256', 'hello'),
    ])->assertOk()->assertJson(['name' => 'lisbon.csv', 'space' => 'personal', 'sha256' => hash('sha256', 'hello')])->json();

    $moved = $sandbox->patchJson(route('drive-sync.items.update', [$this->computerSandbox, $file['id']]), [
        'space' => 'organization', 'parent_id' => null, 'name' => 'Lisbon flights.csv', 'base_revision' => $file['revision'],
    ])->assertOk()->json();
    expect($moved['space'])->toBe('organization')
        ->and(DriveItem::find($file['id'])->updated_by)->toBe($this->user->id);

    $sandbox->get(route('drive-sync.items.content', [$this->computerSandbox, $file['id']]))->assertOk()->assertStreamedContent('hello');

    $sandbox->deleteJson(route('drive-sync.items.destroy', [$this->computerSandbox, $file['id']]))->assertOk();
    expect(DriveItem::find($file['id'])->trashed_at)->not->toBeNull();
})->group('DRIVE-003');

test('a new file whose name was taken meanwhile is kept beside it', function () {
    syncFile($this->org, 'notes.txt', 'from the web');
    $sandbox = fromSandbox($this, $this->sandbox);

    $upload = $sandbox->postJson(route('drive-sync.uploads', $this->sandbox), ['size' => 4])->json('upload');
    $this->call('PUT', route('drive-sync.uploads.part', [$this->sandbox, 0]), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.DriveSync::token($this->sandbox),
        'HTTP_X_ONEDROP_UPLOAD' => $upload,
    ], 'mine');

    $sandbox->postJson(route('drive-sync.files', $this->sandbox), ['upload' => $upload, 'space' => 'organization', 'name' => 'notes.txt'])
        ->assertOk()
        ->assertJsonPath('name', fn (string $name) => str_starts_with($name, 'notes (conflict '));
})->group('DRIVE-003');

test('a sandbox whose owner left the organization reaches nothing', function () {
    config(['app.multi_tenant' => true]);
    $organization = Organization::createNamed('Acme');
    $organization->addMember(User::factory()->create(), OrganizationRole::Owner);
    $organization->addMember($this->user);
    $project = Project::factory()->for($this->user)->create(['organization_id' => $organization->id]);
    $sandbox = Sandbox::factory()->for($project)->create();
    syncFile(new DriveSpace(DriveSpaceKind::Organization, $organization->id), 'a.txt', 'A');

    $organization->removeMember($this->user);

    fromSandbox($this, $sandbox)->getJson(route('drive-sync.state', [$sandbox, 'after' => 0]))
        ->assertJsonPath('places', [])
        ->assertJsonPath('items', []);
})->group('DRIVE-004');
