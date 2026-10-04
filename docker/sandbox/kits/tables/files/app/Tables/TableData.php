<?php

namespace App\Tables;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A table's props for the page (TableData in types.ts): fields, records, views, people and linked titles.
 */
final class TableData
{
    private Computation $computation;

    public function __construct(private Table $table, private ?User $user, ?Computation $computation = null)
    {
        $this->computation = $computation ?? new Computation($user);
        $this->computation->register($table);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $table = $this->table;
        $canUpdate = $table->can($this->user, 'update');
        $max = (int) config('tables.max_rows', 5000);
        $rows = $this->computation->rows($table);

        return [
            'key' => $table->key(),
            'name' => $table->name(),
            'endpoint' => $table->endpoint(),
            'fields' => array_map(fn (Field $field) => $table->fieldData($field, $canUpdate), $table->allFields()),
            'records' => array_map(
                fn (Model $record) => $this->computation->record($table, $record),
                array_values(array_slice($rows, 0, $max, true)),
            ),
            'views' => Views::forTable($table, $this->user),
            'users' => $this->users(),
            'linked' => (object) $this->linked(),
            'tables' => $this->tables(),
            'can' => [
                'edit' => $canUpdate,
                'create' => $table->can($this->user, 'create'),
                'delete' => $table->can($this->user, 'delete'),
                'manageFields' => $table->supportsCustomFields() && $table->can($this->user, 'manageFields'),
                'manageViews' => $table->can($this->user, 'manageViews'),
                'comment' => $table->can($this->user, 'comment'),
            ],
            'me' => $this->user?->getKey(),
            'truncated' => count($rows) > $max,
        ];
    }

    /**
     * @return list<array{id: int, name: string, email: string, avatar: string|null}>
     */
    public function users(): array
    {
        return $this->computation->users($this->table)->map(fn (Model $user) => [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->getAttribute('name'),
            'email' => (string) $user->getAttribute('email'),
            'avatar' => $user->getAttribute('avatar'),
        ])->values()->all();
    }

    /**
     * The titles of the records in every table this one links to.
     *
     * @return array<string, array{name: string, records: list<array{id: int, title: string}>}>
     */
    public function linked(): array
    {
        $linked = [];

        foreach ($this->table->allFields() as $field) {
            $key = (string) $field->option('table');

            if ($field->type !== 'link' || isset($linked[$key]) || ($target = $this->computation->table($key)) === null) {
                continue;
            }

            $linked[$key] = [
                'name' => $target->name(),
                'records' => array_map(
                    fn (Model $record) => ['id' => (int) $record->getKey(), 'title' => $this->computation->title($target, $record)],
                    array_values($this->computation->rows($target)),
                ),
            ];
        }

        return $linked;
    }

    /**
     * Every table the person may see, with its fields, for picking link targets and looked-up fields.
     *
     * @return list<array{key: string, name: string, fields: list<array{key: string, name: string, type: string}>}>
     */
    private function tables(): array
    {
        $tables = [];

        foreach (Tables::keys() as $key) {
            $table = $key === $this->table->key() ? $this->table : $this->computation->table($key);

            if ($table === null || ! $table->can($this->user, 'view')) {
                continue;
            }

            $tables[] = [
                'key' => $key,
                'name' => $table->name(),
                'fields' => array_map(
                    fn (Field $field) => ['key' => $field->key, 'name' => $field->name, 'type' => $field->type],
                    $table->allFields(),
                ),
            ];
        }

        return $tables;
    }
}
