<?php

namespace App\Tables;

use App\Models\User;
use App\Tables\Formula\Formula;
use App\Tables\Formula\FormulaError;
use Illuminate\Database\Eloquent\Model;

/**
 * A formula being written, worked out for the table's first records.
 */
final class FormulaPreview
{
    public const RECORDS = 5;

    /**
     * @return array{ok: bool, error?: string, results?: list<array{id: int, title: string, value: mixed, error: string|null}>}
     */
    public static function make(Table $table, ?User $user, string $formula, string $format = 'auto'): array
    {
        try {
            $withKeys = Fields::formulaKeys($table, $formula);
            Formula::compile($withKeys);
        } catch (FormulaError $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }

        $field = new Field('@preview', 'Preview', 'formula', ['formula' => $withKeys, 'format' => in_array($format, Field::FORMATS, true) ? $format : 'auto'], builtIn: false);
        $computation = new Computation($user);
        $computation->register($table);

        $results = array_map(function (Model $record) use ($table, $field, $computation) {
            [$value, $error] = $computation->cell($table, $record, $field);

            return [
                'id' => (int) $record->getKey(),
                'title' => $computation->title($table, $record),
                'value' => $value,
                'error' => $error,
            ];
        }, array_values(array_slice($computation->rows($table), 0, self::RECORDS, true)));

        return ['ok' => true, 'results' => $results];
    }
}
