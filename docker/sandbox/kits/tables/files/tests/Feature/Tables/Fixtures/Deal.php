<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\HasCustomFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Deal extends Model
{
    use HasCustomFields;

    protected $table = 'kit_test_deals';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'files' => 'array',
            'close_date' => 'date',
            'signed' => 'boolean',
        ];
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'kit_test_contact_deal', 'deal_id', 'contact_id');
    }
}
