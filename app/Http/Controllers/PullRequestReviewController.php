<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ReviewReply;
use App\Models\Task;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestCheckouts;
use App\Sandbox\PullRequestReviews;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A pull request's review comments (GIT-031): handing them to its task's agent, applying a suggestion as written,
 * replying and resolving by hand, and the agent's draft replies, which are edited, posted or discarded.
 */
class PullRequestReviewController extends Controller
{
    /**
     * Send the chosen review threads (every one waiting on someone, by default) to the pull request's task's agent,
     * checking it out first when it has no task.
     */
    public function address(Request $request, Project $project, int $number, GitHubPulls $pulls, PullRequestCheckouts $checkouts, PullRequestReviews $reviews): JsonResponse
    {
        Gate::authorize('update', $project);

        $chosen = $request->validate(['threads' => ['sometimes', 'array'], 'threads.*' => ['string']])['threads'] ?? null;

        return $this->fromGitHub(function () use ($project, $number, $pulls, $checkouts, $reviews, $chosen) {
            $pull = $pulls->find($project, $number);
            $task = $checkouts->taskFor($project, $number);
            $waiting = $reviews->waiting($task, $pulls->threads($project, $number, fresh: true));

            if ($chosen !== null) {
                $waiting = array_values(array_filter($waiting, fn (array $thread) => in_array($thread['id'], $chosen, true)));
            }

            if ($waiting === []) {
                return response()->json(['message' => __('No review comments are waiting on anyone.')], 422);
            }

            if ($task === null) {
                if (($problem = $checkouts->problem($project, $pull)) !== null) {
                    return response()->json(['message' => $problem], 422);
                }

                // Its first message makes the copy on the pull request's branch, then the agent runs (TASK-003).
                $task = $checkouts->newTask($project, $pull);
            }

            // Asked for by hand: the count of rounds in a row starts over.
            $task->update(['pull_request_review_rounds' => 0]);
            $reviews->send($task, $waiting);

            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        });
    }

    /**
     * Apply a thread's ```suggestion as written in the task's copy, push it, and resolve the thread once pushed.
     */
    public function apply(Request $request, Project $project, int $number, GitHubPulls $pulls, PullRequestCheckouts $checkouts, PullRequestReviews $reviews): JsonResponse
    {
        Gate::authorize('update', $project);

        $threadId = $request->validate(['thread_id' => ['required', 'string']])['thread_id'];

        return $this->fromGitHub(function () use ($project, $number, $pulls, $checkouts, $reviews, $threadId) {
            $task = $checkouts->taskFor($project, $number);

            if ($task === null || ! $task->sandbox()->exists()) {
                return response()->json(['message' => __('Check it out as a task first: the suggestion is applied in its copy.')], 422);
            }

            $reviews->apply($task, $this->thread($pulls, $project, $number, $threadId));

            return response()->json(['ok' => true]);
        });
    }

    /**
     * Reply on a thread by hand. OneDrop's app posts it, so it says who wrote it.
     */
    public function reply(Request $request, Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate(['thread_id' => ['required', 'string'], 'body' => ['required', 'string', 'max:10000']]);

        return $this->fromGitHub(function () use ($request, $project, $number, $pulls, $validated) {
            $pulls->reply($project, $number, $validated['thread_id'], trim($validated['body'])."\n\n<sub>{$request->user()->name} via OneDrop</sub>");

            return response()->json(['ok' => true]);
        });
    }

    /**
     * Resolve a thread, or mark it unresolved again.
     */
    public function resolve(Request $request, Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate(['thread_id' => ['required', 'string'], 'resolved' => ['required', 'boolean']]);

        return $this->fromGitHub(function () use ($project, $number, $pulls, $validated) {
            $pulls->resolve($project, $number, $validated['thread_id'], (bool) $validated['resolved']);

            return response()->json(['ok' => true]);
        });
    }

    /**
     * Change a draft reply's words before it's posted.
     */
    public function update(Request $request, Project $project, ReviewReply $reply): JsonResponse
    {
        $this->authorizeReply($project, $reply);

        $body = $request->validate(['body' => ['required', 'string', 'max:5000']])['body'];
        $reply->update(['body' => trim($body)]);

        return response()->json(['reply' => self::describe($reply)]);
    }

    /**
     * Post a draft reply on its thread; a fix resolves it.
     */
    public function post(Project $project, ReviewReply $reply, PullRequestReviews $reviews): JsonResponse
    {
        $this->authorizeReply($project, $reply);

        return $this->fromGitHub(function () use ($reply, $reviews) {
            $reviews->post($reply);

            return response()->json(['reply' => self::describe($reply->refresh())]);
        });
    }

    /**
     * Throw a draft reply away without posting it.
     */
    public function discard(Project $project, ReviewReply $reply): JsonResponse
    {
        $this->authorizeReply($project, $reply);

        $reply->update(['status' => 'discarded']);

        return response()->json(['reply' => self::describe($reply)]);
    }

    /**
     * @return array{id: int, outcome: string, body: string, status: string, commit_sha: ?string, error: ?string, posted_at: ?string}
     */
    public static function describe(ReviewReply $reply): array
    {
        return [
            'id' => $reply->id,
            'outcome' => $reply->outcome,
            'body' => $reply->body,
            'status' => $reply->status,
            'commit_sha' => $reply->commit_sha,
            'error' => $reply->error,
            'posted_at' => $reply->posted_at?->toIso8601String(),
        ];
    }

    /**
     * The pull request's threads as its page shows them: whether each waits on someone, whether its suggestion can be
     * applied, and the agent's newest reply about it.
     *
     * @param  list<array<string, mixed>>  $threads
     * @return list<array<string, mixed>>
     */
    public static function present(?Task $task, array $threads, PullRequestReviews $reviews): array
    {
        $waiting = array_column($reviews->waiting($task, $threads), 'id');
        $replies = $task
            ? $task->reviewReplies()->where('status', '!=', 'discarded')->latest('id')->get()->unique('thread_id')->keyBy('thread_id')
            : collect();

        return array_map(fn (array $thread) => [
            ...$thread,
            'waiting' => in_array($thread['id'], $waiting, true),
            'can_apply' => PullRequestReviews::canApply($thread),
            'reply' => ($reply = $replies->get($thread['id'])) ? self::describe($reply) : null,
        ], $threads);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GitException
     */
    protected function thread(GitHubPulls $pulls, Project $project, int $number, string $threadId): array
    {
        return collect($pulls->threads($project, $number, fresh: true))->firstWhere('id', $threadId)
            ?? throw new GitException(__('That review comment is gone from the pull request.'));
    }

    protected function authorizeReply(Project $project, ReviewReply $reply): void
    {
        Gate::authorize('update', $project);

        abort_unless($reply->task?->project_id === $project->id, 404);
        abort_if($reply->status !== 'draft', 422, __('That reply was already posted or discarded.'));
    }

    /**
     * @param  Closure(): JsonResponse  $callback
     */
    protected function fromGitHub(Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
