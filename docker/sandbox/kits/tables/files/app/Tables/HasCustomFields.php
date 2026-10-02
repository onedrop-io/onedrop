<?php

namespace App\Tables;

/**
 * For a table's model: keeps the values of fields people add in a nullable json "custom_fields" column.
 *
 * @property array<string, mixed>|null $custom_fields
 */
trait HasCustomFields
{
    public function initializeHasCustomFields(): void
    {
        $this->mergeCasts(['custom_fields' => 'array']);
    }

    /**
     * The value of a field people added, by its key ("cf_12").
     */
    public function customField(string $key, mixed $default = null): mixed
    {
        return ($this->custom_fields ?? [])[$key] ?? $default;
    }

    public function setCustomField(string $key, mixed $value): static
    {
        $values = $this->custom_fields ?? [];
        $values[$key] = $value;
        $this->custom_fields = $values;

        return $this;
    }
}
