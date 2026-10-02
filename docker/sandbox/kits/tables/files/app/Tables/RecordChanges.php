<?php

namespace App\Tables;

use App\Events\TableChanged;
use App\Models\TableActivity;
use App\Models\TableComment;
use App\Models\TableField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A batch of creates, updates and deletes from the grid, applied in one transaction once every value is valid.
 */
final class RecordChanges
{
    private Computation $computation;

    public function __construct(private Table $table, private ?User $user)
    {
        $this->computation = new Computation($user);
        $this->computation->register($table);
    }

    /**
     * @param  array{creates?: list<array{values?: array<string, mixed>}>, updates?: list<array{id: int, values?: array<string, mixed>}>, deletes?: list<int>}  $changes
     * @return array{records: list<array<string, mixed>>, deleted: list<int>}
     *
     * @throws ValidationException when a value doesn't fit its field
     */
    public function apply(array $changes): array
    {
        $table = $this->table;
        $creates = array_values($changes['creates'] ?? []);
        $updates = array_values($changes['updates'] ?? []);

        if ($creates !== []) {
            $table->authorize($this->user, 'create');
        }

        $records = $this->recordsToUpdate($updates);
        $deleting = $this->recordsToDelete(array_map('intval', $changes['deletes'] ?? []));

        $normalizer = new Normalizer(
            $table,
            $this->computation,
            $table->supportsCustomFields() && $table->can($this->user, 'manageFields'),
        );

        $errors = [];
        $createValues = [];
        $updateValues = [];

        foreach ($creates as $index => $create) {
            $createValues[$index] = $this->normalize($normalizer, $create['values'] ?? [], "new.{$index}", $errors);
        }

        foreach ($updates as $update) {
            $id = (int) $update['id'];
            $updateValues[$id] = [...$updateValues[$id] ?? [], ...$this->normalize($normalizer, $update['values'] ?? [], (string) $id, $errors)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changed = DB::transaction(function () use ($normalizer, $createValues, $updateValues, $records, $deleting) {
            $this->addChoices($normalizer->addedChoices);

            $changed = [];

            foreach ($createValues as $values) {
                $changed[] = $this->create($values);
            }

            foreach ($updateValues as $id => $values) {
                $this->update($records[$id], $values);
                $changed[] = $id;
            }

            $this->delete($deleting);

            return array_values(array_unique($changed));
        });

        $deleted = $deleting->map(fn (Model $record) => (int) $record->getKey())->values()->all();

        TableChanged::send($table->key(), records: $changed, deleted: $deleted);
        TableChanged::reloadLinking($table->key());

        return [
            'records' => $this->present($changed),
            'deleted' => $deleted,
        ];
    }

    /**
     * @param  list<array{id: int}>  $updates
     * @return array<int, Model>
     */
    private function recordsToUpdate(array $updates): array
    {
        $ids = array_values(array_unique(array_map(fn (array $update) => (int) ($update['id'] ?? 0), $updates)));

        if ($ids === []) {
            return [];
        }

        $records = $this->table->query($this->user)->with($this->table->eagerLoads())->whereKey($ids)->get()
            ->keyBy(fn (Model $record) => (int) $record->getKey());

        abort_if($records->count() !== count($ids), 404);

        foreach ($records as $record) {
            $this->table->authorize($this->user, 'update', $record);
        }

        $this->computation->seed($this->table, $records);

        return $records->all();
    }

    /**
     * Records to delete that the person may see; others are ignored.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Model>
     */
    private function recordsToDelete(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $records = $this->table->query($this->user)->whereKey(array_values(array_unique($ids)))->get();

        foreach ($records as $record) {
            $this->table->authorize($this->user, 'delete', $record);
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function normalize(Normalizer $normalizer, array $input, string $prefix, array &$errors): array
    {
        $values = [];

        foreach ($input as $key => $value) {
            $field = $this->table->field((string) $key);

            if ($field === null) {
                $errors["{$prefix}.{$key}"][] = "There's no field “{$key}”.";

                continue;
            }

            try {
                $values[$field->key] = $normalizer->normalize($field, $value);
            } catch (InvalidValue $invalid) {
                $errors["{$prefix}.{$key}"][] = $invalid->getMessage();
            }
        }

        return $values;
    }

    /**
     * Add options typed into select fields people added.
     *
     * @param  array<string, list<array{name: string, color: string}>>  $added
     */
    private function addChoices(array $added): void
    {
        foreach ($added as $key => $choices) {
            $field = $this->table->field($key);

            if ($field?->model === null) {
                continue;
            }

            $field->options['choices'] = [...$field->choiceList(), ...$choices];
            $field->model->update(['options' => $field->options]);
        }

        if ($added !== []) {
            TableChanged::send($this->table->key(), reload: true);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): int
    {
        $record = $this->table->newModel();
        $record->forceFill($this->table->defaults($this->user));

        $this->fill($record, $values);
        $this->table->saving($record, $this->user);
        $record->save();
        $this->syncLinks($record, $values);

        TableActivity::query()->create([
            'table' => $this->table->key(),
            'record_id' => $record->getKey(),
            'user_id' => $this->user?->getKey(),
            'kind' => 'created',
        ]);

        return (int) $record->getKey();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(Model $record, array $values): void
    {
        $history = [];

        foreach ($values as $key => $value) {
            $field = $this->table->field($key);
            $old = $this->computation->value($this->table, $record, $field);

            if (json_encode($old) === json_encode($value)) {
                continue;
            }

            $history[] = [
                'table' => $this->table->key(),
                'record_id' => $record->getKey(),
                'user_id' => $this->user?->getKey(),
                'kind' => 'updated',
                'field' => $field->key,
                'field_name' => $field->name,
                'from' => $this->computation->display($this->table, $field, $old),
                'to' => $this->computation->display($this->table, $field, $value),
            ];
        }

        $this->fill($record, $values);
        $this->table->saving($record, $this->user);

        if ($record->isDirty()) {
            $record->save();
        } elseif ($history !== []) {
            $record->touch();
        }

        $this->syncLinks($record, $values);

        foreach ($history as $entry) {
            TableActivity::query()->create($entry);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fill(Model $record, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->table->store($record, $this->table->field($key), $value);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function syncLinks(Model $record, array $values): void
    {
        foreach ($values as $key => $value) {
            $field = $this->table->field($key);

            if ($field->relation !== null) {
                $this->table->syncLinks($record, $field, $value);
            }
        }
    }

    /**
     * Delete the records with their files, comments and history, and clear links to them in fields people added.
     *
     * @param  Collection<int, Model>  $records
     */
    private function delete(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $ids = $records->map(fn (Model $record) => (int) $record->getKey())->all();

        foreach ($records as $record) {
            Attachments::deleteFor($this->table, $record);

            foreach ($this->table->allFields() as $field) {
                if ($field->relation !== null) {
                    $this->table->syncLinks($record, $field, []);
                }
            }

            $record->delete();
        }

        TableComment::query()->where('table', $this->table->key())->whereIn('record_id', $ids)->delete();
        TableActivity::query()->where('table', $this->table->key())->whereIn('record_id', $ids)->delete();

        self::clearLinksTo($this->table->key(), $ids);
    }

    /**
     * Take the ids out of every link field people added that links to the table.
     *
     * @param  list<int>  $ids
     */
    public static function clearLinksTo(string $tableKey, array $ids): void
    {
        $links = TableField::query()->where('type', 'link')->get()
            ->filter(fn (TableField $field) => ($field->options['table'] ?? null) === $tableKey && ! ($field->options['inverse'] ?? false));

        foreach ($links as $link) {
            $source = Tables::find($link->table);

            if ($source === null || ! $source->supportsCustomFields()) {
                continue;
            }

            $source->newModel()->newQuery()->whereNotNull('custom_fields')->lazyById()->each(function (Model $record) use ($source, $link, $ids) {
                $values = $source->customValues($record);
                $linked = $values[$link->key()] ?? null;

                if (! is_array($linked) || array_intersect($linked, $ids) === []) {
                    return;
                }

                $values[$link->key()] = array_values(array_diff($linked, $ids));
                $source->setCustomValues($record, $values);
                $record->save();
            });
        }
    }

    /**
     * The changed records, worked out again.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function present(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $table = $this->table->refreshFields();
        $computation = new Computation($this->user);
        $computation->register($table);

        $records = $table->query($this->user)->with($table->eagerLoads())->whereKey($ids)->get()
            ->keyBy(fn (Model $record) => (int) $record->getKey());
        $computation->seed($table, $records);

        return array_values(array_filter(array_map(
            fn (int $id) => $records->has($id) ? $computation->record($table, $records[$id]) : null,
            $ids,
        )));
    }
}
