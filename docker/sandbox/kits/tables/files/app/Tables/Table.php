<?php

namespace App\Tables;

use App\Models\TableField;
use App\Models\User;
use App\Tables\Formula\Formula;
use App\Tables\Formula\FormulaError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use WeakMap;

/**
 * A table of an app's records (deals, tasks...), shown in the grid. Subclass it per table,
 * declare the model and its fields, and register it in config('tables.tables').
 */
abstract class Table
{
    /**
     * The table's Eloquent model.
     *
     * @var class-string<Model>
     */
    protected string $model;

    protected string $key = '';

    /** @var list<Field>|null */
    private ?array $resolvedFields = null;

    /** @var array<string, Field>|null */
    private ?array $fieldsByKey = null;

    private ?bool $customFieldsColumn = null;

    /** @var WeakMap<Model, array<string, mixed>>|null */
    private ?WeakMap $decodedCustomValues = null;

    /**
     * The table's name, e.g. "Deals".
     */
    abstract public function name(): string;

    /**
     * The fields the app was built with, in the order they're shown. Mark one primary().
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * The views the table starts with, stored the first time it's used.
     *
     * @return list<View>
     */
    public function views(): array
    {
        return [View::grid('Grid view')];
    }

    /**
     * Whether the person may: view, create, update, delete, manageFields, manageViews or comment.
     */
    public function can(?User $user, string $ability, ?Model $record = null): bool
    {
        return $user !== null;
    }

    /**
     * The records the person may see. Narrow it for row-level access.
     *
     * @return Builder<Model>
     */
    public function query(?User $user): Builder
    {
        return $this->newModel()->newQuery();
    }

    /**
     * Attributes for new records, e.g. ['stage' => 'Lead', 'owner_id' => $user?->id].
     *
     * @return array<string, mixed>
     */
    public function defaults(?User $user): array
    {
        return [];
    }

    /**
     * Called before each record is saved from the grid.
     */
    public function saving(Model $record, ?User $user): void {}

    /**
     * The people a person field can pick.
     *
     * @return Collection<int, Model>
     */
    public function users(): Collection
    {
        return config('tables.user_model')::query()->orderBy('name')->get();
    }

    /**
     * What a page passes to <DataTable>.
     *
     * @return array<string, mixed>
     */
    public function props(?User $user): array
    {
        return (new TableData($this, $user))->toArray();
    }

    /**
     * @internal Set by Tables, from the table's key in config('tables.tables').
     */
    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function newModel(): Model
    {
        return new $this->model;
    }

    /**
     * The base path of the table's endpoints. A path, not a full URL: the app is opened under several hostnames.
     */
    public function endpoint(): string
    {
        return '/'.trim((string) config('tables.prefix', 'tables'), '/').'/'.$this->key;
    }

    /**
     * Built-in fields, then the fields people added (oldest first).
     *
     * @return list<Field>
     */
    public function allFields(): array
    {
        if ($this->resolvedFields === null) {
            $fields = array_values($this->fields());

            $added = TableField::query()->forTable($this->key)->orderBy('id')->get()
                ->map(fn (TableField $field) => Field::fromModel($field))
                ->all();

            $this->resolvedFields = [...$fields, ...$added];
            $this->fieldsByKey = [];

            foreach ($this->resolvedFields as $field) {
                $this->fieldsByKey[$field->key] = $field;
            }
        }

        return $this->resolvedFields;
    }

    public function field(string $key): ?Field
    {
        $this->allFields();

        return $this->fieldsByKey[$key] ?? null;
    }

    /**
     * The field used as each record's title: the one marked primary(), or the first.
     */
    public function primaryField(): ?Field
    {
        $fields = $this->allFields();

        foreach ($fields as $field) {
            if ($field->isPrimary) {
                return $field;
            }
        }

        return $fields[0] ?? null;
    }

    /**
     * Forget the fields, after they've changed.
     */
    public function refreshFields(): static
    {
        $this->resolvedFields = null;
        $this->fieldsByKey = null;

        return $this;
    }

    /**
     * Whether the model has a custom_fields column, so people can add fields.
     */
    public function supportsCustomFields(): bool
    {
        if ($this->customFieldsColumn === null) {
            $model = $this->newModel();
            $this->customFieldsColumn = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'custom_fields');
        }

        return $this->customFieldsColumn;
    }

    /**
     * Whether the person may, or a 403.
     */
    public function authorize(?User $user, string $ability, ?Model $record = null): void
    {
        abort_unless($this->can($user, $ability, $record), 403);
    }

    /**
     * The record, if the person may see it, or a 404.
     */
    public function findRecordOrFail(?User $user, int|string $id): Model
    {
        $record = $this->query($user)->whereKey($id)->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * Relations to load with the records: those of link fields kept in BelongsToMany relations.
     *
     * @return array<string, callable>
     */
    public function eagerLoads(): array
    {
        $loads = [];

        foreach ($this->allFields() as $field) {
            if ($field->type === 'link' && $field->relation !== null) {
                $loads[$field->relation] = fn ($query) => $query->select($query->getModel()->getQualifiedKeyName());
            }
        }

        return $loads;
    }

    /**
     * The values of the fields people added.
     *
     * @return array<string, mixed>
     */
    public function customValues(Model $record): array
    {
        $this->decodedCustomValues ??= new WeakMap;

        return $this->decodedCustomValues[$record] ??= Values::list($record->getAttribute('custom_fields'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setCustomValues(Model $record, array $values): void
    {
        $record->setAttribute('custom_fields', $record->hasCast('custom_fields') ? $values : json_encode($values));

        if ($this->decodedCustomValues !== null) {
            unset($this->decodedCustomValues[$record]);
        }
    }

    /**
     * The field's value as stored: a column, or an entry in custom_fields.
     */
    public function stored(Model $record, Field $field): mixed
    {
        if ($field->isCustom()) {
            return $this->customValues($record)[$field->key] ?? null;
        }

        return $record->getAttribute($field->key);
    }

    /**
     * The ids a link field holds, before checking the linked records exist.
     *
     * @return list<int>
     */
    public function storedLinkIds(Model $record, Field $field): array
    {
        if ($field->relation !== null) {
            $related = $record->relationLoaded($field->relation)
                ? $record->getRelation($field->relation)
                : $record->{$field->relation}()->get([$record->{$field->relation}()->getRelated()->getQualifiedKeyName()]);

            return $related instanceof EloquentCollection
                ? $related->map(fn (Model $linked) => (int) $linked->getKey())->values()->all()
                : [];
        }

        $stored = $this->stored($record, $field);

        if (is_array($stored) || (is_string($stored) && str_starts_with($stored, '['))) {
            return array_values(array_map('intval', array_filter(Values::list($stored), 'is_numeric')));
        }

        return is_numeric($stored) ? [(int) $stored] : [];
    }

    /**
     * Write a cell value (already normalized) to the record. Links kept in relations are synced after saving, by syncLinks().
     */
    public function store(Model $record, Field $field, mixed $value): void
    {
        if ($field->relation !== null) {
            return;
        }

        if ($field->isCustom()) {
            $values = $this->customValues($record);

            if ($value === null || $value === [] || $value === false) {
                unset($values[$field->key]);
            } else {
                $values[$field->key] = $field->type === 'attachment' ? array_map(Attachments::stored(...), $value) : $value;
            }

            $this->setCustomValues($record, $values);

            return;
        }

        $value = match ($field->type) {
            'link' => $value[0] ?? null,
            'attachment' => array_map(Attachments::stored(...), $value),
            'date' => $value === null || ! $field->option('includeTime') ? $value : Values::parseDate($value)?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            default => $value,
        };

        if (is_array($value) && ! $record->hasCast($field->key)) {
            $value = json_encode($value);
        }

        $record->setAttribute($field->key, $value);
    }

    /**
     * Sync a link kept in a BelongsToMany relation.
     *
     * @param  list<int>  $ids
     */
    public function syncLinks(Model $record, Field $field, array $ids): void
    {
        $relation = $record->{$field->relation}();

        if ($relation instanceof BelongsToMany) {
            $relation->sync($ids);
            $record->unsetRelation($field->relation);
        }
    }

    /**
     * The field as the browser gets it.
     *
     * @return array<string, mixed>
     */
    public function fieldData(Field $field, bool $canUpdate): array
    {
        return [
            'key' => $field->key,
            'name' => $field->name,
            'type' => $field->type,
            'description' => $field->description,
            'builtIn' => $field->builtIn,
            'primary' => $field === $this->primaryField(),
            'readOnly' => ! $canUpdate || $field->isComputed() || $field->locked,
            'options' => (object) $this->optionsData($field),
        ];
    }

    /**
     * The options that apply to the field's type, with formulas in names.
     *
     * @return array<string, mixed>
     */
    public function optionsData(Field $field, int $depth = 0): array
    {
        $options = [];

        foreach (Field::OPTION_KEYS[$field->type] ?? [] as $key) {
            if (array_key_exists($key, $field->options) && $field->options[$key] !== null) {
                $options[$key] = $field->options[$key];
            }
        }

        if ($field->type === 'link') {
            $options['multiple'] = $field->isMultiple();

            if ($field->isInverse()) {
                $options['inverse'] = true;
                unset($options['showInverse']);
            } else {
                $options['showInverse'] = (bool) ($options['showInverse'] ?? false);
                unset($options['inverse'], $options['source']);
            }
        }

        if ($field->type === 'formula') {
            $options['formula'] = $this->formulaWithNames((string) $field->option('formula', ''));
        }

        if ($field->type === 'lookup') {
            $options['result'] = $this->lookupResult($field, $depth);
        }

        return $options;
    }

    /**
     * A stored formula ("{value} * 2") with field names ("{Value} * 2").
     */
    public function formulaWithNames(string $formula): string
    {
        $names = [];

        foreach ($this->allFields() as $field) {
            $names[$field->key] = $field->name;
        }

        try {
            return Formula::keysToNames($formula, $names);
        } catch (FormulaError) {
            return $formula;
        }
    }

    /**
     * How a lookup's values are shown: the looked-up field's type and options, with links and people as text.
     *
     * @return array{type: string, options: object}
     */
    private function lookupResult(Field $lookup, int $depth): array
    {
        $link = $this->field((string) $lookup->option('link'));
        $target = $link === null ? null : Tables::find((string) $link->option('table'));
        $looked = $target?->field((string) $lookup->option('field'));

        if ($looked === null || $depth > 3 || in_array($looked->type, ['link', 'user'], true)) {
            return ['type' => 'text', 'options' => (object) []];
        }

        if ($looked->type === 'lookup') {
            return $target->lookupResult($looked, $depth + 1);
        }

        return ['type' => $looked->type, 'options' => (object) $target->optionsData($looked, $depth + 1)];
    }
}
