<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment on a table's record.
 *
 * @property int $id
 * @property string $table
 * @property int $record_id
 * @property int|null $user_id
 * @property string $body
 */
class TableComment extends Model
{
    /**
     * With microseconds, to keep comments and changes made in the same second in order.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = ['table', 'record_id', 'user_id', 'body'];

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
