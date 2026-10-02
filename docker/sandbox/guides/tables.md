# Tables (the table kit)

Follow this when a Laravel app keeps lists of records people browse and edit: deals, contacts, tasks,
inventory, candidates, tickets. The table kit gives each list an Airtable-style grid: edit cells in place,
paste from a spreadsheet, fill down, undo, saved views (grid, board, calendar, gallery) with filters, sorts
and groups, fields and formulas people add themselves, linked records with lookups and rollups, a record
panel with comments and history, CSV import and export, and live updates.

The kit is for those lists. The rest of the app is still yours to build as a custom app: its home page,
dashboards, public forms and screens shaped around the work (a pipeline review, an interview scorecard).
Those can read the same models.

## Install it, once per app

```bash
/opt/onedrop/kit tables
```

It copies the kit into the app as the app's own code (`app/Tables`, `app/Http/Controllers/Tables`,
`resources/js/components/table`, `config/tables.php`, `routes/tables.php`, a migration and tests in
`tests/Feature/Tables` and `tests/Unit/Tables`), registers `App\Providers\TablesServiceProvider`, installs
its npm packages and migrates. Running it again keeps every file the app already has. You may change any
of it for this app; keep `app/Tables` and `resources/js/components/table/types.ts` in step when you do.

## 1. The model

Give each table's model the columns the app is built with, plus a nullable `custom_fields` JSON column for
the fields people add, and the `HasCustomFields` trait:

```php
Schema::create('deals', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
    $table->decimal('value', 12, 2)->nullable();
    $table->string('stage')->nullable();
    $table->date('close_date')->nullable();
    $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
    $table->json('tags')->nullable();          // multiple select
    $table->json('files')->nullable();         // attachments
    $table->json('custom_fields')->nullable(); // fields people add
    $table->timestamps();
});
```

```php
class Deal extends Model
{
    use HasCustomFields; // App\Tables\HasCustomFields

    protected function casts(): array
    {
        return ['tags' => 'array', 'files' => 'array', 'close_date' => 'date'];
    }
}
```

Make columns nullable or give them a default in `defaults()` (below): people add empty records from the
grid. Without a `custom_fields` column, people can't add fields to that table.

## 2. The table class

One class per table in `app/Tables`, listing the fields the app is built with, in order. The first argument
is the column (or, for worked-out fields, a key of your choosing), the second the name people see:

```php
namespace App\Tables;

use App\Models\Deal;

class DealsTable extends Table
{
    protected string $model = Deal::class;

    public function name(): string
    {
        return 'Deals';
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->primary(),
            Field::link('company_id', 'Company', 'companies'),
            Field::currency('value', 'Value')->precision(0),
            Field::select('stage', 'Stage', ['Lead' => 'gray', 'Qualified' => 'blue', 'Proposal' => 'amber', 'Won' => 'green', 'Lost' => 'red']),
            Field::date('close_date', 'Close date'),
            Field::user('owner_id', 'Owner'),
            Field::multiSelect('tags', 'Tags', ['Hot', 'Renewal']),
            Field::attachments('files', 'Files'),
            Field::formula('weighted', 'Weighted value', 'IF({stage} = "Won", {value}, {value} * 0.5)')->format('currency'),
            Field::lookup('company_city', 'Company city', link: 'company_id', field: 'city'),
            Field::createdAt(),
        ];
    }

    public function views(): array
    {
        return [
            View::grid('All deals')->sort('close_date')->summary('value', 'sum'),
            View::board('Pipeline')->stackBy('stage'),
            View::calendar('Close dates')->dateField('close_date'),
        ];
    }
}
```

Register every table in `config/tables.php`; its key is used in links, URLs and the broadcast channel:

```php
'tables' => [
    'deals' => App\Tables\DealsTable::class,
    'companies' => App\Tables\CompaniesTable::class,
],
```

### Fields

| Builder                                                      | Column                  | Notes                                                                                                                                                |
| ------------------------------------------------------------ | ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `text`, `longText`, `url`, `email`, `phone`                  | string / text           |                                                                                                                                                      |
| `number`, `currency`, `percent`, `rating`                    | numeric                 | `->precision(n)`, `->symbol('€')`, `->max(10)`; a percent is stored as a fraction (0.5 is 50%)                                                       |
| `checkbox`                                                   | boolean                 |                                                                                                                                                      |
| `date`                                                       | date                    | `->includeTime()` for a datetime column                                                                                                              |
| `select($key, $name, $choices)`                              | string                  | `['Name' => 'color']` or `['Name', …]`; colors: gray red orange amber yellow lime green teal cyan blue indigo violet purple pink                     |
| `multiSelect`                                                | json (array cast)       |                                                                                                                                                      |
| `user`                                                       | foreign key to users    |                                                                                                                                                      |
| `link($key, $name, $table)`                                  | foreign key (belongsTo) | one record of `$table`                                                                                                                               |
| `link(...)->relation('contacts')`                            | BelongsToMany relation  | several records, synced through the relation                                                                                                         |
| `linkedFrom($key, $name, table: 'deals', via: 'company_id')` | none                    | read-only: the records of `deals` whose `company_id` is this record                                                                                  |
| `attachments`                                                | json (array cast)       | files go to App Storage's `table-attachments` bucket                                                                                                 |
| `formula($key, $name, $formula)`                             | none                    | Airtable's formula language, fields by key in braces: `{value} * {probability}`; `->format('currency' \| 'number' \| 'percent' \| 'date' \| 'text')` |
| `lookup($key, $name, link:, field:)`                         | none                    | a field of the linked records                                                                                                                        |
| `rollup($key, $name, link:, field:, function:)`              | none                    | sum, average, min, max, count, countAll, join, earliest, latest                                                                                      |
| `count($key, $name, link:)`                                  | none                    | how many records are linked                                                                                                                          |
| `createdAt()`, `updatedAt()`                                 | timestamps              |                                                                                                                                                      |

Every field takes `->primary()` (the record's title: first, frozen, shown in links; one per table) and
`->description('…')`. `link`, `lookup`, `rollup` and `count` name a link field of this table by its key.
People can hide, sort, filter and group by these fields but not rename, retype or delete them; they can add
their own fields of every type (stored in `custom_fields` with keys like `cf_12`).

### Views

The views a table starts with, created the first time it's opened: `View::grid()`, `View::board()`
(`->stackBy('stage')`: a select or person field), `View::calendar()` (`->dateField('close_date')`),
`View::gallery()` (`->cover('files')`). Each takes `->sort($field, 'asc'|'desc')`, `->group($field)` (up to
three), `->filter($field, $operator, $value)` (`->any()` to match any condition), `->hide(...$fields)`,
`->order(...$fields)`, `->width($field, $pixels)`, `->rowHeight('short'|'medium'|'tall'|'extraTall')` and
`->summary($field, 'sum'|'average'|'min'|'max'|'filled'|'empty'|'unique'|…)`. Filter operators include
`is`, `isNot`, `contains`, `isEmpty`, `isNotEmpty`, `lt`, `gt`, `isAnyOf`, `isBefore`, `isAfter` and `isMe`.
After that, people manage views themselves.

### Who can do what

Override these on the table class:

```php
// Abilities: view, create, update, delete, manageFields, manageViews, comment. Default: anyone signed in.
public function can(?User $user, string $ability, ?Model $record = null): bool
{
    return match ($ability) {
        'manageFields', 'delete' => $user?->is_admin ?? false,
        default => $user !== null,
    };
}

// The records someone sees (e.g. a client only sees their own).
public function query(?User $user): Builder
{
    return Deal::query()->where('team_id', $user?->team_id);
}

// Values new records start with.
public function defaults(?User $user): array
{
    return ['stage' => 'Lead', 'owner_id' => $user?->id];
}

// Before each save from the grid (set derived columns, check rules).
public function saving(Model $record, ?User $user): void {}

// The people a person field can pick (default: every user).
public function users(): Collection {}
```

## 3. The page

Pass the table's props to an Inertia page and render `<DataTable>` in a container with a height (it fills
it and scrolls inside):

```php
Route::middleware(['auth'])->get('deals', fn (Request $request) => Inertia::render('deals/index', [
    'table' => Tables::get('deals')->props($request->user()),
]))->name('deals.index');
```

```tsx
import { DataTable } from '@/components/table';
import type { TableData } from '@/components/table';

export default function Deals({ table }: { table: TableData }) {
    return (
        <div className="h-[calc(100svh-5rem)] p-4">
            <DataTable data={table} />
        </div>
    );
}
```

Use the app's layout and page conventions (breadcrumbs, the sidebar link) around it. Link to a record with
`/deals?record=<id>`: the page opens it in the record panel.

The table's records are all sent to the page, up to `tables.max_rows` (5,000). For tables that will
outgrow that, build paged pages of your own instead.

## Live updates

With Reverb set up as in /opt/onedrop/guides/laravel.md, changes reach everyone viewing the table
(private channel `tables.<key>`, authorized by `can('view')`). Without it the grid still works; people see
others' changes on reload.

## Seed data

Seed the records with the models (factories and a seeder), not through the grid. Don't seed fields people
add; that's theirs to do.

## Reading the data elsewhere

The app's own pages use the models as usual. A field someone added is `$deal->customField('cf_12')`; don't
build features on those, since people can rename or delete them. When a field matters to the app's logic,
make it a column and a built-in field.

## Tests

The kit's own tests are in `tests/Feature/Tables` and `tests/Unit/Tables`; keep them passing with
`php artisan test`. Write feature tests for your table classes (permissions, defaults, query scoping) and
browser tests for the pages per /opt/onedrop/guides/tests.md. In the grid, cells have the role `gridcell`
and column headers `columnheader`: double-click a cell (or select it and press Enter) to edit, type, and
press Enter to save; "Add record" adds a row.

## Finishing

Open the page in the preview: add a record, edit a few cells, add a field, switch views. Tell the user in a
sentence or two that their lists work like a spreadsheet: they can edit in place, add their own fields and
formulas, and save views, and that files they attach are in Tools → App Storage under `table-attachments`.
