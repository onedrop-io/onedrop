<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\Field;
use App\Tables\Table;

class ContactsTable extends Table
{
    protected string $model = Contact::class;

    public function name(): string
    {
        return 'Contacts';
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->primary(),
        ];
    }
}
