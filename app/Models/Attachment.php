<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file the user attached to a chat message. Kept privately on the app's disk; a copy goes into
 * the project's sandbox when the agent runs (see OpenCodeRunner).
 *
 * @property int $id
 * @property int|null $message_id
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'mime_type', 'size', 'path'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /**
     * The disk attachments are kept on (private): the app's default, shared by web and queue instances (e.g. object storage on Laravel Cloud).
     */
    public static function disk(): string
    {
        return (string) config('filesystems.default');
    }

    public const MAX_FILES = 10;

    public const MAX_KILOBYTES = 10_240;

    /** Images models can look at; other files are only handed to the agent as files. */
    public const VISIBLE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Validation rules for a message's text and attachments: text is optional when there are files.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $field): array
    {
        return [
            $field => ['required_without:attachments', 'nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * Keep an uploaded file for a message.
     */
    public static function store(Message $message, UploadedFile $file): self
    {
        $path = $file->storeAs("attachments/{$message->project_id}", Str::random(40), self::disk());

        return $message->attachments()->create([
            'name' => Str::limit(basename($file->getClientOriginalName()), 200, '') ?: 'file',
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'path' => $path,
        ]);
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $attachment) => Storage::disk(self::disk())->delete($attachment->path));
    }

    /**
     * Whether a vision model can look at it (PNG, JPEG, GIF or WebP).
     */
    public function isVisibleImage(): bool
    {
        return in_array($this->mime_type, self::VISIBLE_IMAGE_TYPES, true);
    }

    /**
     * The file's contents.
     */
    public function contents(): string
    {
        return (string) Storage::disk(self::disk())->get($this->path);
    }

    /**
     * The message it was sent with.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
