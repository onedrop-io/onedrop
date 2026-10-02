<?php

namespace Tests\Feature\Tables\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model without a custom_fields column: people can't add fields to its table.
 */
class Note extends Model
{
    protected $table = 'kit_test_notes';

    protected $guarded = [];
}
