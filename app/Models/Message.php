<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Sandbox\Agents\Conversation;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property MessageRole $role
 * @property string $content
 * @property bool $queued
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['role', 'content', 'queued'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'queued' => 'boolean',
        ];
    }

    /**
     * Remove the attachments' files too (the database cascade alone would leave them on disk).
     */
    protected static function booted(): void
    {
        // Messages created through a task's chat belong to its project too.
        static::creating(function (self $message) {
            if ($message->task_id !== null && $message->project_id === null) {
                $message->project_id = Task::query()->whereKey($message->task_id)->value('project_id');
            }
        });

        static::deleting(fn (self $message) => $message->attachments->each->delete());
    }

    /**
     * Files the user attached to the message.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    /**
     * The task whose chat the message is in, or null for the project's main chat.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The chat the message is in: its task's, or the project's main one.
     */
    public function conversation(): Conversation
    {
        return $this->task_id !== null ? $this->task : $this->project;
    }

    /**
     * The project the message belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
