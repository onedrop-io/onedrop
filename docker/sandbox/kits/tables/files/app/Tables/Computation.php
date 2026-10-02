<?php

namespace App\Tables;

use App\Models\User;
use App\Tables\Formula\CompiledFormula;
use App\Tables\Formula\Formula;
use App\Tables\Formula\FormulaError;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Works out cell values for one request: lazily, remembering each (table, record, field),
 * and shared across tables, since lookups, rollups and formulas use linked tables' values.
 * Each table's records are loaded with one query, the first time they're needed.
 */
final class Computation
{
    public const CIRCULAR = 'Circular reference';

    /** @var array<string, Table|null> */
    private array $tables = [];

    /** @var array<string, array<int, Model>> */
    private array $rows = [];

    /** @var array<string, true> */
    private array $complete = [];

    /** @var array<string, array{0: mixed, 1: string|null}> */
    private array $cells = [];

    /** @var array<string, mixed> */
    private array $natives = [];

    /** @var array<string, true> */
    private array $computing = [];

    /** @var array<string, string> */
    private array $titles = [];

    /** @var array<string, array<int, list<int>>> */
    private array $inverse = [];

    /** @var array<string, CompiledFormula|FormulaError> */
    private array $formulas = [];

    /** @var array<string, Collection<int, Model>> */
    private array $users = [];

    /** @var array<string, string|null> */
    private array $timestamps = [];

    public function __construct(public readonly ?User $user) {}

    /**
     * Use this instance of the table (e.g. one whose fields were just changed).
     */
    public function register(Table $table): Table
    {
        return $this->tables[$table->key()] = $table;
    }

    public function table(string $key): ?Table
    {
        if (! array_key_exists($key, $this->tables)) {
            $this->tables[$key] = Tables::find($key);
        }

        return $this->tables[$key];
    }

    /**
     * Every record of the table the person may see, up to tables.max_rows (and one more, to tell it's truncated).
     *
     * @return array<int, Model>
     */
    public function rows(Table $table): array
    {
        $key = $table->key();

        if (! isset($this->complete[$key])) {
            $model = $table->newModel();
            $limit = (int) config('tables.max_rows', 5000) + 1;

            $loaded = $table->query($this->user)
                ->with($table->eagerLoads())
                ->orderBy($model->getQualifiedKeyName())
                ->limit($limit)
                ->get();

            $this->rows[$key] = $loaded->keyBy(fn (Model $record) => (int) $record->getKey())->all() + ($this->rows[$key] ?? []);
            $this->complete[$key] = true;
        }

        return $this->rows[$key];
    }

    /**
     * Records already loaded elsewhere, so they aren't queried again.
     *
     * @param  iterable<Model>  $records
     */
    public function seed(Table $table, iterable $records): void
    {
        foreach ($records as $record) {
            $this->rows[$table->key()][(int) $record->getKey()] = $record;
        }
    }

    public function find(Table $table, int $id): ?Model
    {
        return $this->rows[$table->key()][$id] ?? $this->rows($table)[$id] ?? null;
    }

    /**
     * The record as the browser gets it.
     *
     * @return array{id: int, values: object, errors: object, createdAt: string|null, updatedAt: string|null}
     */
    public function record(Table $table, Model $record): array
    {
        $values = [];
        $errors = [];

        foreach ($table->allFields() as $field) {
            [$values[$field->key], $error] = $this->cell($table, $record, $field);

            if ($error !== null) {
                $errors[$field->key] = $error;
            }
        }

        return [
            'id' => (int) $record->getKey(),
            'values' => (object) $values,
            'errors' => (object) $errors,
            'createdAt' => $this->timestamp($record, $record->getCreatedAtColumn()),
            'updatedAt' => $this->timestamp($record, $record->getUpdatedAtColumn()),
        ];
    }

    public function value(Table $table, Model $record, Field $field): mixed
    {
        return $this->cell($table, $record, $field)[0];
    }

    public function error(Table $table, Model $record, Field $field): ?string
    {
        return $this->cell($table, $record, $field)[1];
    }

    /**
     * The value and error of a cell, worked out once.
     *
     * @return array{0: mixed, 1: string|null}
     */
    public function cell(Table $table, Model $record, Field $field): array
    {
        $key = $table->key().'|'.$record->getKey().'|'.$field->key;

        if (isset($this->cells[$key])) {
            return $this->cells[$key];
        }

        if (isset($this->computing[$key])) {
            return [Values::empty($field), self::CIRCULAR];
        }

        $this->computing[$key] = true;

        try {
            $cell = $this->compute($table, $record, $field, $key);
        } finally {
            unset($this->computing[$key]);
        }

        return $this->cells[$key] = $cell;
    }

    /**
     * The record's title: its primary field's text, or "Untitled".
     */
    public function title(Table $table, Model $record): string
    {
        $key = $table->key().'|'.$record->getKey();

        if (! isset($this->titles[$key])) {
            $primary = $table->primaryField();
            $title = $primary === null ? null : $this->display($table, $primary, $this->value($table, $record, $primary));
            $this->titles[$key] = $title === null || trim($title) === '' ? 'Untitled' : trim($title);
        }

        return $this->titles[$key];
    }

    /**
     * The value as text, for titles, history, joins and converting to text.
     */
    public function display(Table $table, Field $field, mixed $value): ?string
    {
        $text = match ($field->type) {
            'number', 'rating' => is_int($value) || is_float($value) ? Values::formatNumber($value, 'number', $field->option('precision')) : null,
            'currency' => is_int($value) || is_float($value) ? Values::formatNumber($value, 'currency', $field->option('precision', 2), $field->option('symbol', '$')) : null,
            'percent' => is_int($value) || is_float($value) ? Values::formatNumber($value, 'percent', $field->option('precision', 0)) : null,
            'checkbox' => $value ? 'checked' : null,
            'date', 'createdAt', 'updatedAt' => $this->displayDate($value, (bool) $field->option('includeTime', in_array($field->type, ['createdAt', 'updatedAt'], true))),
            'multiSelect' => implode(', ', (array) $value),
            'user' => $value === null ? null : $this->userName($table, (int) $value),
            'link' => $this->displayLinks($table, $field, (array) $value),
            'attachment' => implode(', ', array_column((array) $value, 'name')),
            'formula', 'rollup' => $this->displayResult($field, $value),
            'lookup' => $this->displayLookup($table, $field, (array) $value),
            'count' => (string) (int) $value,
            default => $value === null ? null : (is_array($value) ? implode(', ', $value) : (string) $value),
        };

        return $text === '' ? null : $text;
    }

    /**
     * The value formulas get for the field: dates as dates, links as titles, people as names,
     * attachments as file names, lookups as lists.
     *
     * @throws FormulaError when the field's own value couldn't be worked out
     */
    public function native(Table $table, Model $record, Field $field): mixed
    {
        [$value, $error] = $this->cell($table, $record, $field);

        if ($error !== null) {
            throw new FormulaError($error);
        }

        return match ($field->type) {
            'date', 'createdAt', 'updatedAt' => Values::toDate($value),
            'link' => array_map(fn (int $id) => $this->linkedTitle($field, $id), $value),
            'user' => $value === null ? null : $this->userName($table, (int) $value),
            'attachment' => array_column($value, 'name'),
            'formula', 'rollup' => $this->natives[$table->key().'|'.$record->getKey().'|'.$field->key] ?? $value,
            default => $value,
        };
    }

    /**
     * The ids of the records a link field links to, among those the person may see.
     *
     * @return list<int>
     */
    public function linkedIds(Table $table, Model $record, Field $link): array
    {
        return $this->value($table, $record, $link);
    }

    /**
     * The records a link field links to.
     *
     * @return list<Model>
     */
    public function linkedRecords(Table $table, Model $record, Field $link): array
    {
        $target = $this->table((string) $link->option('table'));

        if ($target === null) {
            return [];
        }

        return array_values(array_filter(array_map(fn (int $id) => $this->find($target, $id), $this->linkedIds($table, $record, $link))));
    }

    /**
     * The people a person field can pick, by id.
     *
     * @return Collection<int, Model>
     */
    public function users(Table $table): Collection
    {
        return $this->users[$table->key()] ??= $table->users()->keyBy(fn (Model $user) => (int) $user->getKey());
    }

    public function userName(Table $table, int $id): ?string
    {
        $user = $this->users($table)->get($id);

        if ($user === null) {
            $user = config('tables.user_model')::query()->find($id);
            $this->users[$table->key()]->put($id, $user);
        }

        return $user?->getAttribute('name');
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private function compute(Table $table, Model $record, Field $field, string $key): array
    {
        try {
            return match (true) {
                $field->type === 'link' => [$this->links($table, $record, $field), null],
                $field->type === 'formula' => $this->formula($table, $record, $field, $key),
                $field->type === 'lookup' => $this->lookup($table, $record, $field),
                $field->type === 'rollup' => $this->rollup($table, $record, $field, $key),
                $field->type === 'count' => $this->count($table, $record, $field),
                $field->type === 'createdAt' => [$this->timestamp($record, $field->builtIn ? $field->key : $record->getCreatedAtColumn()), null],
                $field->type === 'updatedAt' => [$this->timestamp($record, $field->builtIn ? $field->key : $record->getUpdatedAtColumn()), null],
                $field->type === 'attachment' => [$this->attachments($table, $table->stored($record, $field)), null],
                default => [Values::fromStored($field, $table->stored($record, $field)), null],
            };
        } catch (FormulaError $error) {
            return [Values::empty($field), $error->getMessage()];
        }
    }

    /**
     * @return list<int>
     */
    private function links(Table $table, Model $record, Field $field): array
    {
        $target = $this->table((string) $field->option('table'));

        if ($target === null) {
            return [];
        }

        $ids = $field->isInverse()
            ? $this->inverseIds($target, (string) $field->option('source'), (int) $record->getKey())
            : $table->storedLinkIds($record, $field);

        $rows = $this->rows($target);

        return array_values(array_unique(array_filter($ids, fn (int $id) => isset($rows[$id]))));
    }

    /**
     * The records of $source whose $linkKey field links to $id.
     *
     * @return list<int>
     */
    private function inverseIds(Table $source, string $linkKey, int $id): array
    {
        $indexKey = $source->key().'|'.$linkKey;

        if (! isset($this->inverse[$indexKey])) {
            $this->inverse[$indexKey] = [];
            $link = $source->field($linkKey);

            if ($link !== null && $link->type === 'link' && ! $link->isInverse()) {
                foreach ($this->rows($source) as $sourceId => $row) {
                    foreach ($source->storedLinkIds($row, $link) as $targetId) {
                        $this->inverse[$indexKey][$targetId][] = $sourceId;
                    }
                }
            }
        }

        return $this->inverse[$indexKey][$id] ?? [];
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private function formula(Table $table, Model $record, Field $field, string $key): array
    {
        $compiled = $this->compiled($table, $field);

        if ($compiled instanceof FormulaError) {
            return [null, $compiled->getMessage()];
        }

        try {
            $result = $compiled->evaluate(fn (string $reference) => $this->formulaInput($table, $record, $reference));
        } catch (FormulaError $error) {
            return [null, $error->getMessage()];
        } catch (Throwable) {
            return [null, "This formula couldn't be worked out."];
        }

        $this->natives[$key] = $result;

        return [Values::formulaCell($result, (string) $field->option('format', 'auto')), null];
    }

    /**
     * The formula, compiled once per table and field.
     */
    public function compiled(Table $table, Field $field): CompiledFormula|FormulaError
    {
        $key = $table->key().'|'.$field->key.'|'.$field->option('formula');

        if (! isset($this->formulas[$key])) {
            try {
                $this->formulas[$key] = Formula::compile((string) $field->option('formula', ''));
            } catch (FormulaError $error) {
                $this->formulas[$key] = $error;
            }
        }

        return $this->formulas[$key];
    }

    /**
     * What a formula gets for "{key}".
     */
    public function formulaInput(Table $table, Model $record, string $reference): mixed
    {
        return match ($reference) {
            '@id' => (int) $record->getKey(),
            '@createdAt' => Values::toDate($this->timestamp($record, $record->getCreatedAtColumn())),
            '@updatedAt' => Values::toDate($this->timestamp($record, $record->getUpdatedAtColumn())),
            default => $this->native(
                $table,
                $record,
                $table->field($reference) ?? throw new FormulaError('This formula uses a field that was deleted.'),
            ),
        };
    }

    /**
     * @return array{0: list<mixed>, 1: string|null}
     */
    private function lookup(Table $table, Model $record, Field $field): array
    {
        [$target, $looked] = $this->followed($table, $field);
        $values = [];

        foreach ($this->linkedRecords($table, $record, $table->field((string) $field->option('link'))) as $linked) {
            [$value, $error] = $this->cell($target, $linked, $looked);

            if ($error === self::CIRCULAR) {
                throw new FormulaError(self::CIRCULAR);
            }

            if ($error !== null) {
                continue;
            }

            $values[] = match ($looked->type) {
                'link' => array_map(fn (int $id) => $this->linkedTitle($looked, $id), $value),
                'user' => $value === null ? null : $this->userName($target, (int) $value),
                default => $value,
            };
        }

        return [Values::flatten($values), null];
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private function rollup(Table $table, Model $record, Field $field, string $key): array
    {
        [$target, $looked] = $this->followed($table, $field);
        $linked = $this->linkedRecords($table, $record, $table->field((string) $field->option('link')));
        $function = (string) $field->option('function', 'sum');

        if ($function === 'countAll') {
            return [count($linked), null];
        }

        $values = [];
        $texts = [];

        foreach ($linked as $row) {
            [$value, $error] = $this->cell($target, $row, $looked);

            if ($error !== null) {
                throw new FormulaError($error === self::CIRCULAR ? self::CIRCULAR : "A linked record's {$looked->name} couldn't be worked out.");
            }

            $values[] = $value;
            $texts[] = $this->display($target, $looked, $value);
        }

        $flat = array_values(array_filter(Values::flatten($values), fn (mixed $value) => Values::isFilled($value)));
        $numbers = array_values(array_filter(array_map(fn (mixed $value) => is_bool($value) ? null : Values::number($value), $flat), fn (mixed $number) => $number !== null));

        if (in_array($function, ['earliest', 'latest'], true)) {
            $dates = array_values(array_filter(array_map(fn (mixed $value) => Values::toDate($value), $flat)));
            $date = $dates === [] ? null : ($function === 'earliest' ? min($dates) : max($dates));
            $this->natives[$key] = $date;
            $dateOnly = $date !== null && is_string($flat[0] ?? null) && strlen($flat[0]) === 10;

            return [$date === null ? null : Values::formatDate($date, ! $dateOnly), null];
        }

        $result = match ($function) {
            'sum' => array_sum($numbers),
            'average' => $numbers === [] ? null : array_sum($numbers) / count($numbers),
            'min' => $numbers === [] ? null : min($numbers),
            'max' => $numbers === [] ? null : max($numbers),
            'count' => count($flat),
            'join' => implode(', ', array_filter($texts, fn (?string $text) => $text !== null && $text !== '')),
            default => throw new FormulaError("“{$function}” isn't a rollup function."),
        };

        return [is_float($result) ? Values::number(round($result, 10)) : $result, null];
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function count(Table $table, Model $record, Field $field): array
    {
        $link = $table->field((string) $field->option('link'));

        if ($link === null || $link->type !== 'link') {
            throw new FormulaError('The link field this counts was deleted.');
        }

        return [count($this->linkedIds($table, $record, $link)), null];
    }

    /**
     * The linked table and field a lookup or rollup follows.
     *
     * @return array{0: Table, 1: Field}
     */
    private function followed(Table $table, Field $field): array
    {
        $link = $table->field((string) $field->option('link'));

        if ($link === null || $link->type !== 'link') {
            throw new FormulaError('The link field this uses was deleted.');
        }

        $target = $this->table((string) $link->option('table'));
        $looked = $target?->field((string) $field->option('field'));

        if ($target === null || $looked === null) {
            throw new FormulaError('The field this looks up was deleted.');
        }

        return [$target, $looked];
    }

    /**
     * @param  array<mixed>  $attachments
     * @return list<array{key: string, name: string, size: int, type: string, url: string}>
     */
    private function attachments(Table $table, mixed $attachments): array
    {
        $field = new Field('', '', 'attachment');

        return array_map(fn (array $attachment) => Attachments::present($table, $attachment), Values::fromStored($field, $attachments));
    }

    private function linkedTitle(Field $link, int $id): string
    {
        $target = $this->table((string) $link->option('table'));
        $record = $target === null ? null : $this->find($target, $id);

        return $record === null ? 'Untitled' : $this->title($target, $record);
    }

    /**
     * @param  array<int>  $ids
     */
    private function displayLinks(Table $table, Field $field, array $ids): ?string
    {
        return implode(', ', array_map(fn (int $id) => $this->linkedTitle($field, $id), $ids));
    }

    /**
     * @param  array<mixed>  $values
     */
    private function displayLookup(Table $table, Field $field, array $values): string
    {
        [$target, $looked] = [null, null];

        try {
            [$target, $looked] = $this->followed($table, $field);
        } catch (FormulaError) {
            //
        }

        $texts = array_map(function (mixed $value) use ($looked, $target) {
            if ($looked === null || in_array($looked->type, ['link', 'user'], true)) {
                return is_array($value) ? implode(', ', array_column($value, 'name')) : (string) $value;
            }

            return $this->display($target, $looked, $looked->type === 'multiSelect' || $looked->type === 'attachment' ? [$value] : $value);
        }, $values);

        return implode(', ', array_filter($texts, fn (?string $text) => $text !== null && $text !== ''));
    }

    private function displayResult(Field $field, mixed $value): ?string
    {
        $format = (string) $field->option('format', 'auto');

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(', ', array_map(fn (mixed $item) => is_scalar($item) ? (string) $item : '', $value)),
            (is_int($value) || is_float($value)) && in_array($format, ['number', 'currency', 'percent'], true) => Values::formatNumber($value, $format, $field->option('precision'), $field->option('symbol')),
            is_int($value) || is_float($value) => Values::formatNumber($value, 'number', $field->option('precision')),
            $format === 'date' && is_string($value) => $this->displayDate($value, strlen($value) > 10),
            default => (string) $value,
        };
    }

    private function displayDate(mixed $value, bool $includeTime): ?string
    {
        $date = Values::toDate($value);

        return $date === null ? null : $date->format($includeTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    private function timestamp(Model $record, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $key = spl_object_id($record).'|'.$column;

        if (! array_key_exists($key, $this->timestamps)) {
            $this->timestamps[$key] = $this->readTimestamp($record, $column);
        }

        return $this->timestamps[$key];
    }

    private function readTimestamp(Model $record, string $column): ?string
    {
        $value = $record->getAttribute($column);

        if ($value === null) {
            return null;
        }

        return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value))->toIso8601String();
    }
}
