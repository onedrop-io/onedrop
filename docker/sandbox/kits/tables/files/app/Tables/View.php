<?php

namespace App\Tables;

use Illuminate\Support\Str;

/**
 * A view a table starts with, declared in Table::views(). Stored the first time the table is used.
 */
class View
{
    /**
     * @var array<string, mixed>
     */
    protected array $config = [];

    final public function __construct(public readonly string $name, public readonly string $type) {}

    public static function grid(string $name): static
    {
        return new static($name, 'grid');
    }

    /**
     * Cards in columns by a single select or person field (the first select field unless stackBy() says).
     */
    public static function board(string $name): static
    {
        return new static($name, 'board');
    }

    /**
     * Records on the days of a date field (the first date field unless dateField() says).
     */
    public static function calendar(string $name): static
    {
        return new static($name, 'calendar');
    }

    public static function gallery(string $name): static
    {
        return new static($name, 'gallery');
    }

    public function sort(string $field, string $direction = 'asc'): static
    {
        $this->config['sorts'][] = ['field' => $field, 'direction' => $direction];

        return $this;
    }

    public function group(string $field, string $direction = 'asc'): static
    {
        $this->config['groups'][] = ['field' => $field, 'direction' => $direction];

        return $this;
    }

    /**
     * Only show records matching the condition, e.g. filter('stage', 'isNot', 'Lost').
     */
    public function filter(string $field, string $operator, mixed $value = null): static
    {
        $this->config['filters']['conditions'][] = array_filter([
            'id' => Str::random(8),
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ], fn (mixed $value) => $value !== null);

        return $this;
    }

    /**
     * Show records matching any of the filters, rather than all of them.
     */
    public function any(): static
    {
        $this->config['filters']['conjunction'] = 'or';

        return $this;
    }

    public function hide(string ...$fields): static
    {
        $this->config['hidden'] = [...$this->config['hidden'] ?? [], ...$fields];

        return $this;
    }

    public function order(string ...$fields): static
    {
        $this->config['order'] = $fields;

        return $this;
    }

    public function width(string $field, int $pixels): static
    {
        $this->config['widths'][$field] = $pixels;

        return $this;
    }

    /**
     * short, medium, tall or extraTall.
     */
    public function rowHeight(string $height): static
    {
        $this->config['rowHeight'] = $height;

        return $this;
    }

    /**
     * Show a summary under the field's column, e.g. summary('value', 'sum').
     */
    public function summary(string $field, string $function): static
    {
        $this->config['summaries'][$field] = $function;

        return $this;
    }

    public function stackBy(string $field): static
    {
        $this->config['stackBy'] = $field;

        return $this;
    }

    public function dateField(string $field): static
    {
        $this->config['dateField'] = $field;

        return $this;
    }

    /**
     * The attachment field shown as each card's cover.
     */
    public function cover(string $field): static
    {
        $this->config['coverField'] = $field;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
