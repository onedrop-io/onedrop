<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Tables\Field;
use App\Tables\Table;

class CompaniesTable extends Table
{
    protected string $model = Company::class;

    public function name(): string
    {
        return 'Companies';
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->primary(),
            Field::text('city', 'City'),
            Field::linkedFrom('deals', 'Deals', table: 'deals', via: 'company_id'),
            Field::rollup('total', 'Total', link: 'deals', field: 'value', function: 'sum'),
            Field::count('deal_count', 'Deal count', link: 'deals'),
        ];
    }
}
