<?php

namespace App\Tables;

use App\Models\TableField;

/**
 * A table's field: one the app was built with (a column, declared in the table class),
 * or one people added (a TableField, kept in the record's custom_fields).
 */
class Field
{
    /**
     * @var list<string>
     */
    public const TYPES = [
        'text', 'longText', 'number', 'currency', 'percent', 'checkbox', 'date', 'select', 'multiSelect',
        'user', 'link', 'attachment', 'rating', 'url', 'email', 'phone', 'formula', 'lookup', 'rollup',
        'count', 'createdAt', 'updatedAt',
    ];

    /**
     * Types whose values are worked out rather than entered.
     *
     * @var list<string>
     */
    public const COMPUTED = ['formula', 'lookup', 'rollup', 'count', 'createdAt', 'updatedAt'];

    /**
     * Option colors, in the order new options get them.
     *
     * @var list<string>
     */
    public const COLORS = [
        'blue', 'cyan', 'teal', 'green', 'lime', 'yellow', 'amber', 'orange', 'red', 'pink',
        'purple', 'violet', 'indigo', 'gray',
    ];

    /**
     * @var list<string>
     */
    public const ROLLUP_FUNCTIONS = ['sum', 'average', 'min', 'max', 'count', 'countAll', 'join', 'earliest', 'latest'];

    /**
     * @var list<string>
     */
    public const FORMATS = ['auto', 'text', 'number', 'currency', 'percent', 'date'];

    /**
     * The options each type has, in the order they're sent.
     *
     * @var array<string, list<string>>
     */
    public const OPTION_KEYS = [
        'select' => ['choices'],
        'multiSelect' => ['choices'],
        'number' => ['precision'],
        'currency' => ['precision', 'symbol'],
        'percent' => ['precision'],
        'date' => ['includeTime'],
        'createdAt' => ['includeTime'],
        'updatedAt' => ['includeTime'],
        'rating' => ['max'],
        'link' => ['table', 'multiple', 'inverse', 'showInverse', 'source'],
        'lookup' => ['link', 'field'],
        'rollup' => ['link', 'field', 'function', 'format', 'precision', 'symbol'],
        'count' => ['link'],
        'formula' => ['formula', 'format', 'precision', 'symbol'],
    ];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $key,
        public string $name,
        public string $type,
        public array $options = [],
        public bool $builtIn = true,
        public bool $isPrimary = false,
        public bool $locked = false,
        public ?string $description = null,
        public ?string $relation = null,
        public ?TableField $model = null,
    ) {}

    public static function text(string $key, string $name): static
    {
        return new static($key, $name, 'text');
    }

    public static function longText(string $key, string $name): static
    {
        return new static($key, $name, 'longText');
    }

    public static function number(string $key, string $name): static
    {
        return new static($key, $name, 'number');
    }

    public static function currency(string $key, string $name): static
    {
        return new static($key, $name, 'currency', ['precision' => 2, 'symbol' => '$']);
    }

    /**
     * Stored as a fraction: 0.5 is 50%.
     */
    public static function percent(string $key, string $name): static
    {
        return new static($key, $name, 'percent', ['precision' => 0]);
    }

    public static function checkbox(string $key, string $name): static
    {
        return new static($key, $name, 'checkbox');
    }

    /**
     * A date column; call includeTime() for a datetime column.
     */
    public static function date(string $key, string $name): static
    {
        return new static($key, $name, 'date', ['includeTime' => false]);
    }

    /**
     * @param  array<string, string>|list<string>  $choices  option name => color, or just names
     */
    public static function select(string $key, string $name, array $choices = []): static
    {
        return (new static($key, $name, 'select'))->choices($choices);
    }

    /**
     * A json column holding a list of option names.
     *
     * @param  array<string, string>|list<string>  $choices  option name => color, or just names
     */
    public static function multiSelect(string $key, string $name, array $choices = []): static
    {
        return (new static($key, $name, 'multiSelect'))->choices($choices);
    }

    /**
     * A foreign key to the users table.
     */
    public static function user(string $key, string $name): static
    {
        return new static($key, $name, 'user');
    }

    /**
     * A foreign key column to one record of another table; call relation() for a BelongsToMany of several.
     */
    public static function link(string $key, string $name, string $table): static
    {
        return new static($key, $name, 'link', ['table' => $table, 'multiple' => false]);
    }

    /**
     * The read-only other side of a link: the records of $table whose $via field links to this record.
     */
    public static function linkedFrom(string $key, string $name, string $table, string $via): static
    {
        return new static($key, $name, 'link', ['table' => $table, 'multiple' => true, 'inverse' => true, 'source' => $via]);
    }

    /**
     * A json column holding a list of uploaded files.
     */
    public static function attachments(string $key, string $name): static
    {
        return new static($key, $name, 'attachment');
    }

    public static function rating(string $key, string $name): static
    {
        return new static($key, $name, 'rating', ['max' => 5]);
    }

    public static function url(string $key, string $name): static
    {
        return new static($key, $name, 'url');
    }

    public static function email(string $key, string $name): static
    {
        return new static($key, $name, 'email');
    }

    public static function phone(string $key, string $name): static
    {
        return new static($key, $name, 'phone');
    }

    /**
     * Worked out from the record's other fields; refer to them by key, e.g. "{value} * {probability}".
     */
    public static function formula(string $key, string $name, string $formula): static
    {
        return new static($key, $name, 'formula', ['formula' => $formula, 'format' => 'auto']);
    }

    /**
     * The values of $field in the records linked by the $link field.
     */
    public static function lookup(string $key, string $name, string $link, string $field): static
    {
        return new static($key, $name, 'lookup', ['link' => $link, 'field' => $field]);
    }

    /**
     * $function (sum, average, min, max, count, countAll, join, earliest, latest) over $field of the linked records.
     */
    public static function rollup(string $key, string $name, string $link, string $field, string $function = 'sum'): static
    {
        return new static($key, $name, 'rollup', ['link' => $link, 'field' => $field, 'function' => $function, 'format' => 'auto']);
    }

    /**
     * The number of records linked by the $link field.
     */
    public static function count(string $key, string $name, string $link): static
    {
        return new static($key, $name, 'count', ['link' => $link]);
    }

    public static function createdAt(string $key = 'created_at', string $name = 'Created'): static
    {
        return new static($key, $name, 'createdAt', ['includeTime' => true]);
    }

    public static function updatedAt(string $key = 'updated_at', string $name = 'Last modified'): static
    {
        return new static($key, $name, 'updatedAt', ['includeTime' => true]);
    }

    /**
     * A field people added.
     */
    public static function fromModel(TableField $field): static
    {
        return new static(
            key: $field->key(),
            name: $field->name,
            type: $field->type,
            options: $field->options ?? [],
            builtIn: false,
            description: $field->description,
            model: $field,
        );
    }

    /**
     * The record's title: shown first, and used for links.
     */
    public function primary(bool $primary = true): static
    {
        $this->isPrimary = $primary;

        return $this;
    }

    /**
     * Shown, but not editable in the grid.
     */
    public function readOnly(bool $readOnly = true): static
    {
        $this->locked = $readOnly;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * A link kept in a BelongsToMany relation of the model, linking several records.
     */
    public function relation(string $relation): static
    {
        $this->relation = $relation;
        $this->options['multiple'] = true;

        return $this;
    }

    /**
     * @param  array<string, string>|list<string>  $choices  option name => color, or just names
     */
    public function choices(array $choices): static
    {
        $list = [];

        foreach ($choices as $name => $color) {
            if (is_int($name)) {
                [$name, $color] = [$color, null];
            }

            $color ??= self::nextColor($list);
            $list[] = ['name' => (string) $name, 'color' => $color];
        }

        $this->options['choices'] = $list;

        return $this;
    }

    public function precision(int $precision): static
    {
        $this->options['precision'] = $precision;

        return $this;
    }

    public function symbol(string $symbol): static
    {
        $this->options['symbol'] = $symbol;

        if ($this->type === 'formula' || $this->type === 'rollup') {
            $this->options['format'] = 'currency';
        }

        return $this;
    }

    public function includeTime(bool $includeTime = true): static
    {
        $this->options['includeTime'] = $includeTime;

        return $this;
    }

    public function max(int $max): static
    {
        $this->options['max'] = $max;

        return $this;
    }

    /**
     * How a formula or rollup's result is shown: auto, text, number, currency, percent or date.
     */
    public function format(string $format): static
    {
        $this->options['format'] = $format;

        return $this;
    }

    /**
     * Whether the other table shows the records linking to it (fields people add).
     */
    public function showInverse(bool $show = true): static
    {
        $this->options['showInverse'] = $show;

        return $this;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function isCustom(): bool
    {
        return ! $this->builtIn;
    }

    /**
     * The other table's side of a link, worked out from it.
     */
    public function isInverse(): bool
    {
        return $this->type === 'link' && ($this->options['inverse'] ?? false);
    }

    public function isComputed(): bool
    {
        return in_array($this->type, self::COMPUTED, true) || $this->isInverse();
    }

    public function isMultiple(): bool
    {
        return $this->type === 'link' && ($this->isInverse() || ($this->options['multiple'] ?? false));
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    public function choiceList(): array
    {
        return array_values($this->options['choices'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function choiceNames(): array
    {
        return array_column($this->choiceList(), 'name');
    }

    /**
     * Why a value can't be entered in this field, or null when it can.
     */
    public function readOnlyReason(): ?string
    {
        return match (true) {
            in_array($this->type, ['createdAt', 'updatedAt'], true) => "{$this->name} is set automatically.",
            $this->isInverse() => "{$this->name} is changed from the other table.",
            $this->isComputed() => "{$this->name} is worked out from other fields.",
            $this->locked => "{$this->name} can't be changed.",
            default => null,
        };
    }

    /**
     * The first color not used by the choices yet.
     *
     * @param  list<array{name: string, color: string}>  $choices
     */
    public static function nextColor(array $choices): string
    {
        $used = array_column($choices, 'color');

        foreach (self::COLORS as $color) {
            if (! in_array($color, $used, true)) {
                return $color;
            }
        }

        return self::COLORS[count($choices) % count(self::COLORS)];
    }
}
