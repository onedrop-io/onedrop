<?php

use App\Jobs\CheckTurnOutcome;
use App\Jobs\ExplainAgentFailure;
use App\Jobs\RefreshPullRequestTasks;
use App\Jobs\RunAgentTask;
use App\Jobs\WatchAgentRun;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('agent turns run on the agent queue, so a message never waits behind a task\'s copy', function () {
    config(['queue.agents' => 'agents']);
    Queue::fake();
    $project = Project::factory()->for(User::factory())->create();
    $message = Message::factory()->for($project)->create();

    RunAgentTask::dispatch($project, $message);
    WatchAgentRun::dispatch($project, $message);
    CheckTurnOutcome::dispatch($project, 1);
    ExplainAgentFailure::dispatch($project, $message->id, 'generic', 'error', '[]');

    foreach ([RunAgentTask::class, WatchAgentRun::class, CheckTurnOutcome::class, ExplainAgentFailure::class] as $job) {
        Queue::assertPushedOn('agents', $job);
    }
})->group('AGT-001');

test('agent turns share the default queue when no agent queue is set', function () {
    config(['queue.agents' => null]);
    Queue::fake();
    $project = Project::factory()->for(User::factory())->create();

    RunAgentTask::dispatch($project, Message::factory()->for($project)->create());

    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->queue === null);
})->group('AGT-001');

test('every job that takes a model is dropped when the model is deleted, not failed', function () {
    $missing = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $file) => 'App\\Jobs\\'.basename($file, '.php'))
        ->filter(function (string $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            return collect($constructor?->getParameters() ?? [])->contains(function (ReflectionParameter $parameter) {
                $type = $parameter->getType();
                $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

                return collect($types)->contains(fn ($type) => $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class));
            });
        })
        ->reject(fn (string $class) => (new ReflectionClass($class))->getAttributes(DeleteWhenMissingModels::class) !== [])
        ->values()
        ->all();

    expect($missing)->toBe([]);
})->group('PRJ-003');

test('jobs still queued for a deleted project are dropped quietly', function () {
    config(['queue.default' => 'database', 'queue.agents' => null]);
    $project = Project::factory()->for(User::factory())->create();
    $message = Message::factory()->for($project)->create();

    RefreshPullRequestTasks::dispatch($project);
    RunAgentTask::dispatch($project, $message);
    $project->delete();

    // A worker stops after any job once its process is past --memory (128 MB by default), and a whole suite run in
    // one process (CI) is well past it: the second job would be left queued.
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->group('PRJ-003');
