<?php

namespace App\Tables;

use App\Events\TableChanged;
use App\Models\TableField;
use App\Models\User;
use App\Tables\Formula\Formula;
use App\Tables\Formula\FormulaError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adding, changing, duplicating and deleting the fields people add (TableField), converting values when a type changes.
 */
final class Fields
{
    public function __construct(private Table $table, private ?User $user)
    {
        $table->authorize($user, 'manageFields');

        if (! $table->supportsCustomFields()) {
            throw ValidationException::withMessages([
                'table' => "Fields can't be added to {$table->name()}: its records have no place to keep them (a custom_fields column).",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input  FieldInput
     */
    public function create(array $input): Field
    {
        $name = $this->name($input['name'] ?? null);
        $type = $this->type($input['type'] ?? null);
        $options = $this->options($type, $input['options'] ?? [], null);

        $field = DB::transaction(function () use ($name, $type, $options, $input) {
            $model = TableField::query()->create([
                'table' => $this->table->key(),
                'name' => $name,
                'type' => $type,
                'description' => $this->description($input['description'] ?? null),
                'options' => $options,
            ]);

            if ($type === 'link' && ($options['showInverse'] ?? false)) {
                $this->createInverse($model);
            }

            return Field::fromModel($model);
        });

        $this->changed($field);

        return $field;
    }

    /**
     * @param  array<string, mixed>  $input  FieldInput, partial
     */
    public function update(string $key, array $input): void
    {
        $field = $this->editable($key);
        $model = $field->model;

        if ($field->isInverse() && isset($input['type']) && $input['type'] !== 'link') {
            throw ValidationException::withMessages(['type' => "{$field->name} shows the links from {$this->tableName($field->option('table'))}, so its type can't be changed."]);
        }

        $name = array_key_exists('name', $input) ? $this->name($input['name'], $field) : $field->name;
        $description = array_key_exists('description', $input) ? $this->description($input['description']) : $field->description;

        if ($field->isInverse()) {
            $model->update(['name' => $name, 'description' => $description]);
            $this->changed($field);

            return;
        }

        $type = array_key_exists('type', $input) ? $this->type($input['type']) : $field->type;
        $optionsInput = $type === $field->type ? [...$field->options, ...$input['options'] ?? []] : $input['options'] ?? [];

        if ($type !== $field->type && in_array($type, ['select', 'multiSelect'], true) && in_array($field->type, ['select', 'multiSelect'], true) && ! isset($input['options']['choices'])) {
            $optionsInput['choices'] = $field->choiceList();
        }

        $options = $this->options($type, $optionsInput, $field);
        $renames = array_filter((array) ($input['renames'] ?? []), fn (mixed $new, mixed $old) => is_string($new) && is_string($old), ARRAY_FILTER_USE_BOTH);

        DB::transaction(function () use ($field, $model, $name, $description, $type, $options, $renames) {
            $before = clone $field;
            $old = $this->valuesOf($before);

            $model->update(['name' => $name, 'type' => $type, 'description' => $description, 'options' => $options]);
            $this->table->refreshFields();
            $after = $this->table->field($field->key);

            $this->convert($before, $after, $old, $renames);
            $this->syncInverse($before, $after);
        });

        $this->changed($field);
    }

    public function delete(string $key): void
    {
        $field = $this->editable($key);

        DB::transaction(function () use ($field) {
            $this->deleteField($this->table, $field);
        });

        $this->changed($field);
    }

    /**
     * A copy of the field and its values, named "<name> copy".
     */
    public function duplicate(string $key): Field
    {
        $field = $this->table->field($key);

        abort_if($field === null, 404);

        if ($field->isInverse()) {
            throw ValidationException::withMessages(['field' => "{$field->name} shows links from another table, so it can't be duplicated."]);
        }

        $options = $field->options;

        if ($field->type === 'link') {
            $options['multiple'] = $field->isMultiple();
            $options['showInverse'] = false;
        }

        $copy = DB::transaction(function () use ($field, $options) {
            $model = TableField::query()->create([
                'table' => $this->table->key(),
                'name' => $this->uniqueName($this->table, "{$field->name} copy"),
                'type' => $field->type,
                'description' => $field->description,
                'options' => $options,
            ]);
            $copy = Field::fromModel($model);

            if (! $field->isComputed()) {
                $values = $this->valuesOf($field);

                $this->eachRecord(function (Model $record) use ($copy, $values) {
                    $value = $values[(int) $record->getKey()]['value'] ?? null;

                    if (Values::isFilled($value)) {
                        $this->table->store($record, $copy, $value);
                        $record->save();
                    }
                });
            }

            return $copy;
        });

        $this->changed($copy);

        return $copy;
    }

    /**
     * Delete a field people added: its values, its place in views, and its other side.
     */
    private function deleteField(Table $table, Field $field): void
    {
        if ($field->isInverse()) {
            $source = TableField::query()->find(TableField::idFromKey((string) $field->option('source')));

            if ($source !== null) {
                $source->update(['options' => [...$source->options ?? [], 'showInverse' => false]]);
            }
        } elseif ($field->type === 'link') {
            foreach ($this->inversesOf($field) as $inverse) {
                $target = Tables::find($inverse->table);

                if ($target !== null) {
                    Views::forgetField($target, $inverse->key());
                }

                $inverse->delete();
            }
        }

        if (! $field->isComputed() && $table->supportsCustomFields()) {
            $table->newModel()->newQuery()->whereNotNull('custom_fields')->lazyById()->each(function (Model $record) use ($table, $field) {
                $values = $table->customValues($record);

                if (array_key_exists($field->key, $values)) {
                    unset($values[$field->key]);
                    $table->setCustomValues($record, $values);
                    $record->save();
                }
            });
        }

        $field->model?->delete();
        $table->refreshFields();
        Views::forgetField($table, $field->key);
    }

    /**
     * A field people added, or a 404 / 422 for built-in ones.
     */
    private function editable(string $key): Field
    {
        $field = $this->table->field($key);

        abort_if($field === null, 404);

        if ($field->builtIn) {
            throw ValidationException::withMessages(['field' => "{$field->name} is part of the app, so it can't be renamed, retyped or deleted."]);
        }

        return $field;
    }

    private function name(mixed $name, ?Field $except = null): string
    {
        $name = is_string($name) ? trim($name) : '';

        $error = match (true) {
            $name === '' => 'Give the field a name.',
            mb_strlen($name) > 100 => 'Field names can be up to 100 characters.',
            str_contains($name, '{') || str_contains($name, '}') => "Field names can't have braces in them.",
            ! $this->isUniqueName($this->table, $name, $except) => "There's already a field called “{$name}”.",
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['name' => $error]);
        }

        return $name;
    }

    private function isUniqueName(Table $table, string $name, ?Field $except = null): bool
    {
        foreach ($table->allFields() as $field) {
            if ($field->key !== $except?->key && mb_strtolower($field->name) === mb_strtolower($name)) {
                return false;
            }
        }

        return true;
    }

    private function uniqueName(Table $table, string $name): string
    {
        $name = mb_substr(str_replace(['{', '}'], '', $name), 0, 95);
        $candidate = $name;

        for ($number = 2; ! $this->isUniqueName($table, $candidate); $number++) {
            $candidate = "{$name} {$number}";
        }

        return $candidate;
    }

    private function type(mixed $type): string
    {
        if (! is_string($type) || ! in_array($type, Field::TYPES, true)) {
            throw ValidationException::withMessages(['type' => is_string($type) ? "“{$type}” isn't a field type." : 'Choose a type for the field.']);
        }

        return $type;
    }

    private function description(mixed $description): ?string
    {
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 5000)) {
            throw ValidationException::withMessages(['description' => 'Descriptions can be up to 5,000 characters.']);
        }

        $description = $description === null ? null : trim($description);

        return $description === '' ? null : $description;
    }

    /**
     * The options to store for the type, checked.
     *
     * @return array<string, mixed>
     */
    private function options(string $type, mixed $input, ?Field $existing): array
    {
        $input = is_array($input) ? $input : [];
        $fail = fn (string $key, string $message) => throw ValidationException::withMessages(["options.{$key}" => $message]);
        $precision = function (?int $default) use ($input, $fail): ?int {
            $precision = $input['precision'] ?? $default;

            if ($precision !== null && (! is_numeric($precision) || (int) $precision != $precision || $precision < 0 || $precision > 8)) {
                $fail('precision', 'Decimal places go from 0 to 8.');
            }

            return $precision === null ? null : (int) $precision;
        };
        $symbol = function (?string $default) use ($input, $fail): ?string {
            $symbol = $input['symbol'] ?? $default;

            if ($symbol !== null && (! is_string($symbol) || mb_strlen($symbol) > 5)) {
                $fail('symbol', 'Currency symbols can be up to 5 characters.');
            }

            return $symbol;
        };
        $format = function () use ($input, $fail): string {
            $format = $input['format'] ?? 'auto';

            if (! in_array($format, Field::FORMATS, true)) {
                $fail('format', "“{$format}” isn't a format.");
            }

            return $format;
        };

        $options = match ($type) {
            'select', 'multiSelect' => ['choices' => $this->choices($input['choices'] ?? [])],
            'number' => ['precision' => $precision(null)],
            'currency' => ['precision' => $precision(2), 'symbol' => $symbol('$')],
            'percent' => ['precision' => $precision(0)],
            'date' => ['includeTime' => (bool) ($input['includeTime'] ?? false)],
            'createdAt', 'updatedAt' => ['includeTime' => (bool) ($input['includeTime'] ?? true)],
            'rating' => ['max' => $this->ratingMax($input['max'] ?? 5)],
            'link' => $this->linkOptions($input),
            'lookup' => $this->followOptions($input),
            'count' => ['link' => $this->followOptions($input, withField: false)['link']],
            'rollup' => [
                ...$this->followOptions($input),
                'function' => in_array($input['function'] ?? 'sum', Field::ROLLUP_FUNCTIONS, true) ? ($input['function'] ?? 'sum') : $fail('function', "“{$input['function']}” isn't a rollup function."),
                'format' => $format(),
                'precision' => $precision(null),
                'symbol' => $symbol(null),
            ],
            'formula' => [
                'formula' => $this->formula($input['formula'] ?? null, $existing),
                'format' => $format(),
                'precision' => $precision(null),
                'symbol' => $symbol(null),
            ],
            default => [],
        };

        if (in_array($type, ['lookup', 'rollup'], true) && $existing !== null && $this->refersTo($this->table->key(), $existing->key, $type, $options)) {
            $fail('field', 'This field refers to itself.');
        }

        return array_filter($options, fn (mixed $value) => $value !== null);
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    private function choices(mixed $input): array
    {
        $choices = [];
        $seen = [];

        foreach (is_array($input) ? $input : [] as $choice) {
            $name = is_array($choice) ? trim((string) ($choice['name'] ?? '')) : trim((string) $choice);
            $color = is_array($choice) ? ($choice['color'] ?? null) : null;

            $error = match (true) {
                $name === '' => 'Each option needs a name.',
                isset($seen[mb_strtolower($name)]) => "“{$name}” is listed twice.",
                $color !== null && ! in_array($color, Field::COLORS, true) => "“{$color}” isn't a color.",
                default => null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(['options.choices' => $error]);
            }

            $seen[mb_strtolower($name)] = true;
            $choices[] = ['name' => $name, 'color' => $color ?? Field::nextColor($choices)];
        }

        return $choices;
    }

    private function ratingMax(mixed $max): int
    {
        if (! is_numeric($max) || (int) $max != $max || $max < 1 || $max > 10) {
            throw ValidationException::withMessages(['options.max' => 'Ratings go up to between 1 and 10 stars.']);
        }

        return (int) $max;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{table: string, multiple: bool, showInverse: bool}
     */
    private function linkOptions(array $input): array
    {
        $table = $input['table'] ?? null;

        if (! is_string($table) || $table === '') {
            throw ValidationException::withMessages(['options.table' => 'Choose a table to link to.']);
        }

        if (! Tables::has($table) || ! Tables::get($table)->can($this->user, 'view')) {
            throw ValidationException::withMessages(['options.table' => "There's no table “{$table}”."]);
        }

        return [
            'table' => $table,
            'multiple' => (bool) ($input['multiple'] ?? true),
            'showInverse' => (bool) ($input['showInverse'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{link: string, field?: string}
     */
    private function followOptions(array $input, bool $withField = true): array
    {
        $link = $this->table->field((string) ($input['link'] ?? ''));

        if ($link === null || $link->type !== 'link') {
            throw ValidationException::withMessages(['options.link' => isset($input['link']) ? "“{$input['link']}” isn't a link field in {$this->table->name()}." : 'Choose a link field.']);
        }

        if (! $withField) {
            return ['link' => $link->key];
        }

        $target = Tables::find((string) $link->option('table'));
        $field = $target?->field((string) ($input['field'] ?? ''));

        if ($field === null) {
            throw ValidationException::withMessages(['options.field' => 'Choose a field of '.($target?->name() ?? 'the linked table').'.']);
        }

        return ['link' => $link->key, 'field' => $field->key];
    }

    /**
     * The formula with field keys, checked.
     */
    private function formula(mixed $formula, ?Field $existing): string
    {
        if (! is_string($formula) || trim($formula) === '') {
            throw ValidationException::withMessages(['options.formula' => 'Write a formula.']);
        }

        try {
            $withKeys = self::formulaKeys($this->table, $formula);
            $compiled = Formula::compile($withKeys);
        } catch (FormulaError $error) {
            throw ValidationException::withMessages(['options.formula' => $error->getMessage()]);
        }

        if ($existing !== null && $this->refersTo($this->table->key(), $existing->key, 'formula', ['formula' => $withKeys], $compiled->references())) {
            throw ValidationException::withMessages(['options.formula' => 'This formula refers to itself.']);
        }

        return $withKeys;
    }

    /**
     * A formula with names ("{Value} * 2") with keys instead ("{value} * 2").
     *
     * @throws FormulaError
     */
    public static function formulaKeys(Table $table, string $formula): string
    {
        $keys = [];

        foreach ($table->allFields() as $field) {
            $keys[$field->name] = $field->key;
        }

        return Formula::namesToKeys($formula, $keys);
    }

    /**
     * Whether the field, defined with these options, reaches itself through formulas, lookups and rollups.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>|null  $references
     */
    private function refersTo(string $tableKey, string $fieldKey, string $type, array $options, ?array $references = null): bool
    {
        $self = "{$tableKey}|{$fieldKey}";
        $seen = [];
        $pending = $this->dependencies($this->table, $type, $options, $references);

        while ($pending !== []) {
            [$table, $key] = array_pop($pending);
            $node = $table->key().'|'.$key;

            if ($node === $self) {
                return true;
            }

            if (isset($seen[$node]) || ($field = $table->field($key)) === null) {
                continue;
            }

            $seen[$node] = true;
            array_push($pending, ...$this->dependencies($table, $field->type, $field->options));
        }

        return false;
    }

    /**
     * The fields a field's value comes from.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>|null  $references
     * @return list<array{0: Table, 1: string}>
     */
    private function dependencies(Table $table, string $type, array $options, ?array $references = null): array
    {
        if ($type === 'formula') {
            try {
                $references ??= Formula::references((string) ($options['formula'] ?? ''));
            } catch (FormulaError) {
                return [];
            }

            return array_map(fn (string $key) => [$table, $key], array_values(array_filter($references, fn (string $key) => ! str_starts_with($key, '@'))));
        }

        if (in_array($type, ['lookup', 'rollup'], true)) {
            $link = $table->field((string) ($options['link'] ?? ''));
            $target = $link === null ? null : ($table->key() === $link->option('table') ? $table : Tables::find((string) $link->option('table')));

            return $target === null ? [] : [[$target, (string) ($options['field'] ?? '')]];
        }

        return [];
    }

    /**
     * Every record's value and text before a change, by record id.
     *
     * @return array<int, array{value: mixed, text: string|null}>
     */
    private function valuesOf(Field $field): array
    {
        $computation = new Computation($this->user);
        $computation->register($this->table);
        $records = $this->table->newModel()->newQuery()->with($this->table->eagerLoads())->get();
        $computation->seed($this->table, $records);
        $values = [];

        foreach ($records as $record) {
            $value = $computation->value($this->table, $record, $field);
            $values[(int) $record->getKey()] = [
                'value' => $value,
                'text' => $computation->display($this->table, $field, $value),
                'parts' => is_array($value) ? $this->parts($computation, $field, $value) : null,
            ];
        }

        return $values;
    }

    /**
     * Each item of a list value as text.
     *
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function parts(Computation $computation, Field $field, array $value): array
    {
        $single = $field->type === 'lookup' ? new Field('', '', 'text') : $field;

        return array_values(array_filter(array_map(
            fn (mixed $item) => $computation->display($this->table, $single, in_array($field->type, ['multiSelect', 'link', 'attachment'], true) ? [$item] : $item),
            $value,
        ), fn (?string $text) => $text !== null && $text !== ''));
    }

    /**
     * Rewrite every record's value for the field's new type or options, keeping what converts.
     *
     * @param  array<int, array{value: mixed, text: string|null, parts: list<string>|null}>  $old
     * @param  array<string, string>  $renames
     */
    private function convert(Field $before, Field $after, array $old, array $renames): void
    {
        if ($after->isComputed()) {
            $this->eachRecord(fn (Model $record) => $this->clear($record, $after));

            return;
        }

        $sameType = $before->type === $after->type;

        if ($sameType && ! in_array($after->type, ['select', 'multiSelect', 'link', 'rating'], true)) {
            return;
        }

        $computation = new Computation($this->user);
        $computation->register($this->table);
        $normalizer = new Normalizer($this->table, $computation, true);
        $converted = [];

        foreach ($old as $id => $was) {
            $input = $sameType ? $this->sameTypeInput($before, $after, $was['value'], $renames) : $this->convertInput($before, $after, $was);

            try {
                $converted[$id] = $normalizer->normalize($after, $input);
            } catch (InvalidValue) {
                $converted[$id] = Values::empty($after);
            }
        }

        if (($added = $normalizer->addedChoices[$after->key] ?? []) !== []) {
            $after->options['choices'] = [...$after->choiceList(), ...$added];
            $after->model->update(['options' => $after->options]);
        }

        $this->eachRecord(function (Model $record) use ($after, $converted) {
            $this->table->store($record, $after, $converted[(int) $record->getKey()] ?? Values::empty($after));

            if ($record->isDirty()) {
                $record->save();
            }
        });
    }

    /**
     * The value to keep when only the options changed: renamed options follow, removed ones go.
     *
     * @param  array<string, string>  $renames
     */
    private function sameTypeInput(Field $before, Field $after, mixed $value, array $renames): mixed
    {
        return match ($after->type) {
            'select' => $value === null ? null : (in_array($renamed = $renames[$value] ?? $value, $after->choiceNames(), true) ? $renamed : null),
            'multiSelect' => array_values(array_filter(
                array_map(fn (string $name) => $renames[$name] ?? $name, (array) $value),
                fn (string $name) => in_array($name, $after->choiceNames(), true),
            )),
            'link' => $before->option('table') !== $after->option('table') ? [] : ($after->isMultiple() ? $value : array_slice((array) $value, 0, 1)),
            'rating' => is_int($value) ? min($value, (int) $after->option('max', 5)) : null,
            default => $value,
        };
    }

    /**
     * What to give the new type, from the old value and its text.
     *
     * @param  array{value: mixed, text: string|null, parts: list<string>|null}  $was
     */
    private function convertInput(Field $before, Field $after, array $was): mixed
    {
        ['value' => $value, 'text' => $text, 'parts' => $parts] = $was;
        $isNumber = is_int($value) || is_float($value);

        return match ($after->type) {
            'text', 'longText', 'url', 'email', 'phone' => $text,
            'number', 'currency', 'percent' => $isNumber ? $value : Values::parseNumber((string) $text, $after->type === 'percent'),
            'rating' => $isNumber ? max(0, min((int) round($value), (int) $after->option('max', 5))) : null,
            'checkbox' => match (true) {
                is_bool($value) => $value,
                $isNumber => $value != 0,
                default => Values::parseBool((string) $text) ?? Values::isFilled($value),
            },
            'date' => is_string($value) && Values::toDate($value) !== null ? $value : $text,
            'select' => $parts !== null ? ($parts[0] ?? null) : $text,
            'multiSelect' => $parts ?? $text,
            'user' => $before->type === 'user' ? $value : $text,
            'link' => $before->type === 'link' && $before->option('table') === $after->option('table') ? $value : ($parts ?? $text),
            'attachment' => $before->type === 'attachment' ? $value : null,
            default => null,
        };
    }

    private function clear(Model $record, Field $field): void
    {
        $values = $this->table->customValues($record);

        if (array_key_exists($field->key, $values)) {
            unset($values[$field->key]);
            $this->table->setCustomValues($record, $values);
            $record->save();
        }
    }

    /**
     * Every record of the table, not just those the person sees, since a field's values change for everyone.
     */
    private function eachRecord(callable $callback): void
    {
        $this->table->newModel()->newQuery()->lazyById()->each($callback);
    }

    /**
     * Create or delete the other table's side of a link when showInverse or the linked table changes.
     */
    private function syncInverse(Field $before, Field $after): void
    {
        $had = $before->type === 'link' && ($before->options['showInverse'] ?? false);
        $has = $after->type === 'link' && ($after->options['showInverse'] ?? false);
        $moved = $had && $has && $before->option('table') !== $after->option('table');

        if ($had && (! $has || $moved)) {
            foreach ($this->inversesOf($before) as $inverse) {
                $target = Tables::find($inverse->table);

                if ($target !== null) {
                    Views::forgetField($target, $inverse->key());
                }

                $inverse->delete();
            }
        }

        if ($has && (! $had || $moved)) {
            $this->createInverse($after->model);
        }
    }

    private function createInverse(TableField $link): void
    {
        $target = Tables::get((string) $link->options['table']);

        TableField::query()->create([
            'table' => $target->key(),
            'name' => $this->uniqueName($target, $this->table->name()),
            'type' => 'link',
            'options' => ['table' => $this->table->key(), 'multiple' => true, 'inverse' => true, 'source' => $link->key()],
        ]);

        $target->refreshFields();
    }

    /**
     * @return iterable<TableField>
     */
    private function inversesOf(Field $link): iterable
    {
        return TableField::query()->where('type', 'link')->get()->filter(
            fn (TableField $field) => ($field->options['inverse'] ?? false)
                && ($field->options['table'] ?? null) === $this->table->key()
                && ($field->options['source'] ?? null) === $link->key,
        );
    }

    private function tableName(mixed $key): string
    {
        return Tables::find((string) $key)?->name() ?? 'another table';
    }

    /**
     * Tell everyone viewing this table, the tables linking to it, and the field's linked table, to reload.
     */
    private function changed(Field $field): void
    {
        $this->table->refreshFields();

        TableChanged::send($this->table->key(), reload: true);
        TableChanged::reloadLinking($this->table->key());

        if ($field->type === 'link' && $field->option('table') !== $this->table->key()) {
            TableChanged::send((string) $field->option('table'), reload: true);
        }
    }
}
