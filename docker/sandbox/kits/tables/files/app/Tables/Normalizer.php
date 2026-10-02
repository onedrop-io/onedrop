<?php

namespace App\Tables;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns what people type, paste or send into a field's value, leniently: numbers from "$1,200",
 * checkboxes from "yes", dates from common formats, options and people by name, links by title.
 * Refuses what doesn't fit with a plain reason (InvalidValue).
 */
final class Normalizer
{
    /**
     * Options typed into select fields people added, to add to the fields, by field key.
     *
     * @var array<string, list<array{name: string, color: string}>>
     */
    public array $addedChoices = [];

    /** @var array<string, array<string, list<int>>> */
    private array $titles = [];

    public function __construct(
        private Table $table,
        private Computation $computation,
        private bool $canAddChoices,
    ) {}

    /**
     * The field's cell value for the input.
     *
     * @throws InvalidValue
     */
    public function normalize(Field $field, mixed $input): mixed
    {
        if (($reason = $field->readOnlyReason()) !== null) {
            throw new InvalidValue($reason);
        }

        if ($input === null || $input === '' || $input === []) {
            return Values::empty($field);
        }

        return match ($field->type) {
            'text', 'phone', 'url' => trim($this->text($input)),
            'longText' => $this->text($input),
            'email' => $this->email($input),
            'number', 'currency', 'percent' => $this->number($field, $input),
            'rating' => $this->rating($field, $input),
            'checkbox' => Values::parseBool(is_array($input) ? reset($input) : $input) ?? throw new InvalidValue("{$this->quote($input)} isn't yes or no."),
            'date' => $this->date($field, $input),
            'select' => $this->select($field, is_array($input) ? (string) reset($input) : $this->text($input)),
            'multiSelect' => $this->multiSelect($field, $input),
            'user' => $this->user($field, $input),
            'link' => $this->link($field, $input),
            'attachment' => $this->attachments($field, $input),
            default => throw new InvalidValue("{$field->name} can't be changed."),
        };
    }

    private function text(mixed $input): string
    {
        if (is_array($input)) {
            return implode(', ', array_map(fn (mixed $item) => is_scalar($item) ? (string) $item : '', $input));
        }

        if (is_bool($input)) {
            return $input ? 'true' : 'false';
        }

        return is_scalar($input) ? (string) $input : '';
    }

    private function email(mixed $input): ?string
    {
        $email = trim($this->text($input));

        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidValue("{$this->quote($email)} isn't an email address.");
        }

        return $email;
    }

    private function number(Field $field, mixed $input): int|float|null
    {
        $number = Values::parseNumber(is_array($input) && count($input) === 1 ? reset($input) : $input, $field->type === 'percent');

        if ($number === null) {
            if (is_string($input) && trim($input) === '') {
                return null;
            }

            throw new InvalidValue("{$this->quote($input)} isn't a number.");
        }

        return $number;
    }

    private function rating(Field $field, mixed $input): ?int
    {
        $max = (int) $field->option('max', 5);
        $number = Values::parseNumber($input);

        if ($number === null || ! is_int($number) || $number < 0 || $number > $max) {
            throw new InvalidValue("{$field->name} takes a whole number from 0 to {$max}.");
        }

        return $number;
    }

    private function date(Field $field, mixed $input): ?string
    {
        $date = Values::parseDate(is_string($input) ? trim($input) : $input);

        if ($date === null) {
            throw new InvalidValue("{$this->quote($input)} isn't a date.");
        }

        return Values::formatDate($date, (bool) $field->option('includeTime', false));
    }

    private function select(Field $field, string $input): ?string
    {
        $name = trim($input);

        return $name === '' ? null : $this->choice($field, $name);
    }

    /**
     * @return list<string>
     */
    private function multiSelect(Field $field, mixed $input): array
    {
        $names = is_array($input) ? $input : $this->splitText($field, $this->text($input));
        $chosen = [];

        foreach ($names as $name) {
            $name = trim($this->text($name));

            if ($name !== '') {
                $chosen[] = $this->choice($field, $name);
            }
        }

        return array_values(array_unique($chosen));
    }

    /**
     * Comma-separated names, unless the whole text is one option ("Hot, cold").
     *
     * @return list<string>
     */
    private function splitText(Field $field, string $text): array
    {
        foreach ($this->choices($field) as $choice) {
            if (strcasecmp($choice['name'], trim($text)) === 0) {
                return [$text];
            }
        }

        return explode(',', $text);
    }

    /**
     * The option by exact name, then ignoring case; a new one for fields people added.
     */
    private function choice(Field $field, string $name): string
    {
        $choices = $this->choices($field);

        foreach ($choices as $choice) {
            if ($choice['name'] === $name) {
                return $choice['name'];
            }
        }

        foreach ($choices as $choice) {
            if (mb_strtolower($choice['name']) === mb_strtolower($name)) {
                return $choice['name'];
            }
        }

        if (! $this->canAddChoices || $field->builtIn) {
            throw new InvalidValue("“{$name}” isn't an option for {$field->name}.");
        }

        $this->addedChoices[$field->key][] = ['name' => $name, 'color' => Field::nextColor($choices)];

        return $name;
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    private function choices(Field $field): array
    {
        return [...$field->choiceList(), ...$this->addedChoices[$field->key] ?? []];
    }

    private function user(Field $field, mixed $input): ?int
    {
        $input = is_array($input) ? reset($input) : $input;
        $users = $this->computation->users($this->table);

        if (is_int($input) || (is_string($input) && ctype_digit(trim($input)))) {
            if ($users->has((int) $input)) {
                return (int) $input;
            }

            throw new InvalidValue("That person can't be picked for {$field->name}.");
        }

        $text = mb_strtolower(trim($this->text($input)));

        if ($text === '') {
            return null;
        }

        $match = $users->first(fn (Model $user) => mb_strtolower((string) $user->getAttribute('email')) === $text)
            ?? $users->first(fn (Model $user) => mb_strtolower((string) $user->getAttribute('name')) === $text);

        if ($match === null) {
            throw new InvalidValue("No one called {$this->quote($input)} can be picked for {$field->name}.");
        }

        return (int) $match->getKey();
    }

    /**
     * @return list<int>
     */
    private function link(Field $field, mixed $input): array
    {
        $target = $this->computation->table((string) $field->option('table'));

        if ($target === null) {
            throw new InvalidValue("{$field->name} links to a table that doesn't exist.");
        }

        $rows = $this->computation->rows($target);
        $items = is_array($input) ? $input : [$input];
        $ids = [];

        foreach ($items as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit(trim($item)) && isset($rows[(int) $item]))) {
                isset($rows[(int) $item]) || throw new InvalidValue("There's no record {$item} in {$target->name()}.");
                $ids[] = (int) $item;

                continue;
            }

            $text = trim($this->text($item));

            if ($text === '') {
                continue;
            }

            $found = $this->findByTitle($target, $text);

            if ($found === null && ! is_array($input) && str_contains($text, ',')) {
                foreach (explode(',', $text) as $part) {
                    if (trim($part) !== '') {
                        $ids[] = $this->findByTitle($target, trim($part)) ?? throw new InvalidValue('“'.trim($part)."” isn't a record in {$target->name()}.");
                    }
                }

                continue;
            }

            $ids[] = $found ?? throw new InvalidValue("“{$text}” isn't a record in {$target->name()}.");
        }

        $ids = array_values(array_unique($ids));

        if (! $field->isMultiple() && count($ids) > 1) {
            throw new InvalidValue("{$field->name} can link only one record.");
        }

        return $ids;
    }

    private function findByTitle(Table $target, string $title): ?int
    {
        $key = $target->key();

        if (! isset($this->titles[$key])) {
            $this->titles[$key] = [];

            foreach ($this->computation->rows($target) as $id => $record) {
                $this->titles[$key][mb_strtolower($this->computation->title($target, $record))][] = $id;
            }
        }

        return $this->titles[$key][mb_strtolower($title)][0] ?? null;
    }

    /**
     * @return list<array{key: string, name: string, size: int, type: string, url: string}>
     */
    private function attachments(Field $field, mixed $input): array
    {
        $attachments = [];

        foreach (is_array($input) && ! array_is_list($input) ? [$input] : (array) $input as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['key'] ?? null) || ! Attachments::belongsTo($this->table, $attachment['key'])) {
                throw new InvalidValue("{$field->name} has a file that wasn't uploaded here.");
            }

            $attachments[] = Attachments::present($this->table, [
                'key' => $attachment['key'],
                'name' => is_string($attachment['name'] ?? null) && $attachment['name'] !== '' ? $attachment['name'] : basename($attachment['key']),
                'size' => (int) ($attachment['size'] ?? Attachments::disk()->size($attachment['key'])),
                'type' => is_string($attachment['type'] ?? null) ? $attachment['type'] : (string) Attachments::disk()->mimeType($attachment['key']),
            ]);
        }

        return $attachments;
    }

    private function quote(mixed $input): string
    {
        return '“'.trim($this->text($input)).'”';
    }
}
