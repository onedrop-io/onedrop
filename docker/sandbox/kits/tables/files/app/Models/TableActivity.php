<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a table's record: created (through a form, when via is set), or a field updated from one value to another.
 * Values are kept as display text, so the history survives the field being deleted.
 *
 * @property int $id
 * @property string $table
 * @property int $record_id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $field
 * @property string|null $field_name
 * @property string|null $from
 * @property string|null $to
 * @property string|null $via The form view a created record was sent through
 */
class TableActivity extends Model
{
    public const UPDATED_AT = null;

    /**
     * With microseconds, to keep comments and changes made in the same second in order.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = ['table', 'record_id', 'user_id', 'kind', 'field', 'field_name', 'from', 'to', 'via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('tables.user_model'));
    }
}
