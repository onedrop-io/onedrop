<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\HasCustomFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasCustomFields;

    protected $table = 'kit_test_companies';

    protected $guarded = [];

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'company_id');
    }
}
