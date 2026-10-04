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

    /**
     * Records as bars on a time line, from dateField() to endField() (the first date field unless dateField() says;
     * one-day bars without endField()), by scale(): day, week (default), month or quarter.
     */
    public static function timeline(string $name): static
    {
        return new static($name, 'timeline');
    }

    /**
     * A form that adds a record. Asks for every field people can fill in (the primary one required) unless fields() says;
     * its title is the view's name unless title() says. Signed-in people who may create records can send it; public()
     * also gives it a link anyone can send it from.
     */
    public static function form(string $name): static
    {
        return new static($name, 'form');
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
     * timeline: the date field bars end on.
     */
    public function endField(string $field): static
    {
        $this->config['endField'] = $field;

        return $this;
    }

    /**
     * timeline: day, week, month or quarter.
     */
    public function scale(string $scale): static
    {
        $this->config['timelineScale'] = $scale;

        return $this;
    }

    /**
     * form: the fields it asks for, in order. Keys with true are required: fields(['name' => true, 'email' => true, 'notes']).
     *
     * @param  array<int|string, string|bool>  $fields
     */
    public function fields(array $fields): static
    {
        $this->config['form']['fields'] = [];

        foreach ($fields as $key => $value) {
            $this->config['form']['fields'][] = is_int($key)
                ? ['key' => (string) $value, 'required' => false]
                : ['key' => $key, 'required' => (bool) $value];
        }

        return $this;
    }

    /**
     * form: a help line shown under one of its fields.
     */
    public function help(string $field, string $help): static
    {
        foreach ($this->config['form']['fields'] ?? [] as $index => $formField) {
            if ($formField['key'] === $field) {
                $this->config['form']['fields'][$index]['help'] = $help;
            }
        }

        return $this;
    }

    public function title(string $title): static
    {
        $this->config['form']['title'] = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->config['form']['description'] = $description;

        return $this;
    }

    /**
     * form: the send button's text ("Send").
     */
    public function submitLabel(string $label): static
    {
        $this->config['form']['submitLabel'] = $label;

        return $this;
    }

    /**
     * form: the message shown after sending.
     */
    public function thankYou(string $message): static
    {
        $this->config['form']['thankYou'] = $message;

        return $this;
    }

    /**
     * form: whether "Send another" is offered after sending (it is by default).
     */
    public function allowAnother(bool $allow = true): static
    {
        $this->config['form']['allowAnother'] = $allow;

        return $this;
    }

    /**
     * form: anyone with its link can send it, without signing in. Leaves out person and link fields.
     */
    public function public(bool $public = true): static
    {
        $this->config['form']['public'] = $public;

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
