<?php

namespace App\Tables;

use App\Models\TableActivity;
use App\Models\TableComment;
use Illuminate\Database\Eloquent\Model;

/**
 * A record's comments and history, as ActivityItems.
 */
final class Activity
{
    public const LIMIT = 200;

    /**
     * Comments and changes, oldest first, the last 200.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRecord(Table $table, Model $record): array
    {
        $id = (int) $record->getKey();

        $comments = TableComment::query()->with('user')->where('table', $table->key())->where('record_id', $id)
            ->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (TableComment $comment) => [...self::comment($comment), 'sequence' => $comment->created_at?->format('Y-m-d H:i:s.u').'|'.$comment->id]);

        $changes = TableActivity::query()->with('user')->where('table', $table->key())->where('record_id', $id)
            ->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (TableActivity $activity) => [...self::change($activity), 'sequence' => $activity->created_at?->format('Y-m-d H:i:s.u').'|'.$activity->id]);

        return $comments->concat($changes)
            ->sortBy('sequence')
            ->take(-self::LIMIT)
            ->map(function (array $item) {
                unset($item['sequence']);

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function comment(TableComment $comment): array
    {
        return [
            'id' => 'comment-'.$comment->id,
            'kind' => 'comment',
            'user' => self::user($comment->user),
            'createdAt' => $comment->created_at?->toIso8601String(),
            'commentId' => $comment->id,
            'body' => $comment->body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function change(TableActivity $activity): array
    {
        $item = [
            'id' => 'activity-'.$activity->id,
            'kind' => $activity->kind,
            'user' => self::user($activity->user),
            'createdAt' => $activity->created_at?->toIso8601String(),
        ];

        if ($activity->kind === 'created' && $activity->via !== null) {
            $item['via'] = $activity->via;
        }

        if ($activity->kind === 'updated') {
            $item += [
                'field' => $activity->field,
                'fieldName' => $activity->field_name,
                'from' => $activity->from,
                'to' => $activity->to,
            ];
        }

        return $item;
    }

    /**
     * @return array{id: int, name: string, avatar: string|null}|null
     */
    private static function user(?Model $user): ?array
    {
        return $user === null ? null : [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->getAttribute('name'),
            'avatar' => $user->getAttribute('avatar'),
        ];
    }
}
