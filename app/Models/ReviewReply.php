<?php

namespace App\Models;

use Database\Factories\ReviewReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a pull request's task's agent says about one review thread it was handed (GIT-031): it fixed it, declined it
 * (and why), or needs the user. A draft until it's posted on the thread (fixed ones resolve it) or discarded.
 *
 * @property int $id
 * @property int $task_id
 * @property string $thread_id GitHub's id for the review thread
 * @property string|null $path
 * @property int|null $line
 * @property string $outcome fixed, declined, question or applied (a suggestion applied as written)
 * @property string $body The reply to the reviewer, or the question for the user
 * @property string $status draft, posted or discarded
 * @property string|null $commit_sha The pushed commit a fix is in
 * @property int|null $github_comment_id The reply's comment on GitHub, once posted
 * @property string|null $error Why GitHub refused it
 * @property Carbon|null $posted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['task_id', 'thread_id', 'path', 'line', 'outcome', 'body', 'status', 'commit_sha', 'github_comment_id', 'error', 'posted_at'])]
class ReviewReply extends Model
{
    /** @use HasFactory<ReviewReplyFactory> */
    use HasFactory;

    /** Outcomes that change the code, so their reply names the commit and the thread is resolved. */
    public const FIXES = ['fixed', 'applied'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'github_comment_id' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isFix(): bool
    {
        return in_array($this->outcome, self::FIXES, true);
    }
}
