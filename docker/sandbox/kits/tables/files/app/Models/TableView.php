<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved view of a table: shared, or personal when user_id is set.
 *
 * @property int $id
 * @property string $table
 * @property int|null $user_id
 * @property string $name
 * @property string $type
 * @property array<string, mixed>|null $config
 * @property int $position
 */
class TableView extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['table', 'user_id', 'name', 'type', 'config', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('tables.user_model'));
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * The table's shared views and the given person's own.
     *
     * @param  Builder<TableView>  $query
     */
    public function scopeVisibleTo(Builder $query, string $table, ?int $userId): void
    {
        $query->where('table', $table)
            ->where(fn (Builder $query) => $query->whereNull('user_id')->when($userId !== null, fn (Builder $query) => $query->orWhere('user_id', $userId)))
            ->orderBy('position')
            ->orderBy('id');
    }
}
