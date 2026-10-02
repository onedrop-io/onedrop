<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A field people added to a table. Its values live in each record's custom_fields, under "cf_<id>".
 *
 * @property int $id
 * @property string $table
 * @property string $name
 * @property string $type
 * @property string|null $description
 * @property array<string, mixed>|null $options
 */
class TableField extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['table', 'name', 'type', 'description', 'options'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function key(): string
    {
        return 'cf_'.$this->id;
    }

    /**
     * @param  Builder<TableField>  $query
     */
    public function scopeForTable(Builder $query, string $table): void
    {
        $query->where('table', $table);
    }

    /**
     * The TableField id in a "cf_<id>" key, if it is one.
     */
    public static function idFromKey(string $key): ?int
    {
        return preg_match('/^cf_(\d+)$/', $key, $matches) ? (int) $matches[1] : null;
    }
}
