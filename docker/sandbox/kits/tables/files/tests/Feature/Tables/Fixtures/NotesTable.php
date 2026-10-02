<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\Field;
use App\Tables\Table;

class NotesTable extends Table
{
    protected string $model = Note::class;

    public function name(): string
    {
        return 'Notes';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Title')->primary(),
        ];
    }
}
