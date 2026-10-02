<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\HasCustomFields;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasCustomFields;

    protected $table = 'kit_test_contacts';

    protected $guarded = [];
}
