<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Jobs\DestroySandbox;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('deleting an account deletes its projects, taking apps offline and removing attachments and sandboxes', function () {
    Queue::fake();
    Storage::fake(Attachment::DISK);
    $publisher = new FakePublisher;
    app()->instance(Publisher::class, $publisher);

    $user = User::factory()->create();
    $published = Project::factory()->for($user)->create(['publish_status' => PublishStatus::Live]);
    $publisher->published[$published->id] = PublishVisibility::Private;
    Sandbox::factory()->for($published)->create(['external_id' => 'sbx-1']);
    Sandbox::factory()->for(Project::factory()->for($user))->create(['external_id' => 'sbx-2']);
    $attachment = Attachment::store(Message::factory()->for($published)->create(), UploadedFile::fake()->image('shot.png'));
    $someoneElses = Sandbox::factory()->create(['external_id' => 'sbx-3']);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect(Project::where('user_id', $user->id)->exists())->toBeFalse()
        ->and($publisher->published)->toBe([])
        ->and($someoneElses->fresh())->not->toBeNull();
    Storage::disk(Attachment::DISK)->assertMissing($attachment->path);
    Queue::assertPushed(DestroySandbox::class, 2);
    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'sbx-1' && $job->provider === 'fake');
    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'sbx-2');
})->group('AUTH-002');
