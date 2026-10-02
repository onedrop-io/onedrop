<?php

namespace Tests\Feature\Tables;

use App\Models\TableField;
use App\Models\TableView;
use App\Tables\Tables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\Deal;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-003')]
class FieldsTest extends TablesTestCase
{
    #[Test]
    public function people_add_fields_that_are_kept_in_custom_fields(): void
    {
        $table = $this->actingAs($this->user)->postJson('/tables/deals/fields', [
            'name' => 'Priority',
            'type' => 'select',
            'description' => 'How soon',
            'options' => ['choices' => [['name' => 'High', 'color' => 'red'], ['name' => 'Low']], 'precision' => 3],
        ])->assertOk()->json('table');

        $field = collect($table['fields'])->last();
        $this->assertSame([
            'key' => 'cf_'.TableField::query()->value('id'),
            'name' => 'Priority',
            'type' => 'select',
            'description' => 'How soon',
            'builtIn' => false,
            'primary' => false,
            'readOnly' => false,
            'options' => ['choices' => [['name' => 'High', 'color' => 'red'], ['name' => 'Low', 'color' => 'blue']]],
        ], $field);

        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$field['key'] => 'High']);
        $this->assertSame([$field['key'] => 'High'], $deal->fresh()->custom_fields);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidFields(): array
    {
        return [
            'no name' => [['name' => ' ', 'type' => 'text'], 'name', 'Give the field a name.'],
            'long name' => [['name' => str_repeat('a', 101), 'type' => 'text'], 'name', 'Field names can be up to 100 characters.'],
            'taken name' => [['name' => 'stage', 'type' => 'text'], 'name', 'There\'s already a field called “stage”.'],
            'braces' => [['name' => 'A {b}', 'type' => 'text'], 'name', 'Field names can\'t have braces in them.'],
            'type' => [['name' => 'X', 'type' => 'spreadsheet'], 'type', '“spreadsheet” isn\'t a field type.'],
            'empty option' => [['name' => 'X', 'type' => 'select', 'options' => ['choices' => [['name' => '']]]], 'options.choices', 'Each option needs a name.'],
            'repeated option' => [['name' => 'X', 'type' => 'select', 'options' => ['choices' => [['name' => 'A'], ['name' => 'a']]]], 'options.choices', '“a” is listed twice.'],
            'color' => [['name' => 'X', 'type' => 'multiSelect', 'options' => ['choices' => [['name' => 'A', 'color' => 'plaid']]]], 'options.choices', '“plaid” isn\'t a color.'],
            'precision' => [['name' => 'X', 'type' => 'number', 'options' => ['precision' => 12]], 'options.precision', 'Decimal places go from 0 to 8.'],
            'rating' => [['name' => 'X', 'type' => 'rating', 'options' => ['max' => 20]], 'options.max', 'Ratings go up to between 1 and 10 stars.'],
            'no link table' => [['name' => 'X', 'type' => 'link'], 'options.table', 'Choose a table to link to.'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFields')]
    public function fields_are_checked(array $input, string $key, string $message): void
    {
        $this->actingAs($this->user)->postJson('/tables/deals/fields', $input)
            ->assertUnprocessable()
            ->assertJsonPath('errors', [$key => [$message]]);
    }

    #[Test]
    public function built_in_fields_cant_be_renamed_retyped_or_deleted(): void
    {
        $message = ['field' => ['Stage is part of the app, so it can\'t be renamed, retyped or deleted.']];

        $this->actingAs($this->user)->patchJson('/tables/deals/fields/stage', ['name' => 'Phase'])->assertUnprocessable()->assertJsonPath('errors', $message);
        $this->actingAs($this->user)->patchJson('/tables/deals/fields/stage', ['type' => 'text'])->assertUnprocessable()->assertJsonPath('errors', $message);
        $this->actingAs($this->user)->deleteJson('/tables/deals/fields/stage')->assertUnprocessable()->assertJsonPath('errors', $message);
        $this->actingAs($this->user)->deleteJson('/tables/deals/fields/nope')->assertNotFound();
    }

    #[Test]
    public function tables_without_a_custom_fields_column_cant_have_fields_added(): void
    {
        $this->assertFalse($this->props('notes')['can']['manageFields']);

        $this->actingAs($this->user)->postJson('/tables/notes/fields', ['name' => 'X', 'type' => 'text'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['table' => ['Fields can\'t be added to Notes: its records have no place to keep them (a custom_fields column).']]);
    }

    #[Test]
    public function deleting_a_field_removes_its_values_and_takes_it_out_of_every_view(): void
    {
        $key = $this->addField('deals', ['name' => 'Score', 'type' => 'number']);
        $other = $this->addField('deals', ['name' => 'Other', 'type' => 'text']);
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$key => 5, $other => 'kept']);
        $view = TableView::query()->where('table', 'deals')->first();
        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['config' => [
            'filters' => ['conjunction' => 'or', 'conditions' => [['id' => 'a', 'field' => $key, 'operator' => 'gt', 'value' => 1], ['id' => 'b', 'field' => 'name', 'operator' => 'isNotEmpty']]],
            'sorts' => [['field' => $key, 'direction' => 'desc'], ['field' => 'name', 'direction' => 'asc']],
            'groups' => [['field' => $key, 'direction' => 'asc']],
            'hidden' => [$key, 'notes'],
            'order' => [$key, 'name'],
            'widths' => [$key => 200, 'name' => 300],
            'summaries' => [$key => 'sum'],
            'stackBy' => $key,
            'dateField' => $key,
            'coverField' => $key,
        ]])->assertOk();

        $this->actingAs($this->user)->deleteJson("/tables/deals/fields/{$key}")->assertOk();

        $this->assertSame([$other => 'kept'], $deal->fresh()->custom_fields);
        $config = $view->fresh()->config;
        $this->assertSame([['id' => 'b', 'field' => 'name', 'operator' => 'isNotEmpty']], $config['filters']['conditions']);
        $this->assertSame([['field' => 'name', 'direction' => 'asc']], $config['sorts']);
        $this->assertSame([], $config['groups']);
        $this->assertSame(['notes'], $config['hidden']);
        $this->assertSame(['name'], $config['order']);
        $this->assertSame(['name' => 300], $config['widths']);
        $this->assertSame([], $config['summaries']);
        $this->assertNull($config['stackBy']);
        $this->assertNull($config['dateField']);
        $this->assertNull($config['coverField']);
    }

    #[Test]
    public function a_field_is_duplicated_with_its_values(): void
    {
        $key = $this->addField('deals', ['name' => 'Score', 'type' => 'number', 'options' => ['precision' => 1]]);
        $deal = $this->deal(['stage' => 'Won']);
        $this->updateRecord('deals', $deal->id, [$key => 5]);

        $fields = $this->actingAs($this->user)->postJson("/tables/deals/fields/{$key}/duplicate")->assertOk()->json('table.fields');
        $this->actingAs($this->user)->postJson('/tables/deals/fields/stage/duplicate')->assertOk();

        $copy = collect($fields)->firstWhere('name', 'Score copy');
        $this->assertSame(['precision' => 1], $copy['options']);
        $stageCopy = Tables::get('deals')->allFields()[array_key_last(Tables::get('deals')->allFields())];
        $this->assertSame('Stage copy', $stageCopy->name);
        $this->assertFalse($stageCopy->builtIn);

        $values = $deal->fresh()->custom_fields;
        $this->assertSame(5, $values[$copy['key']]);
        $this->assertSame('Won', $values[$stageCopy->key]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed, 3: array<string, mixed>, 4: mixed}>
     */
    public static function conversions(): array
    {
        return [
            'text to number' => ['text', [], '$1,200', ['type' => 'number'], 1200],
            'text that isn\'t a number' => ['text', [], 'many', ['type' => 'number'], null],
            'number to text' => ['currency', ['precision' => 2, 'symbol' => '€'], 1200.5, ['type' => 'text'], '€1,200.50'],
            'text to checkbox' => ['text', [], 'yes', ['type' => 'checkbox'], true],
            'any text to checkbox' => ['text', [], 'something', ['type' => 'checkbox'], true],
            'text to date' => ['text', [], 'Oct 2, 2026', ['type' => 'date'], '2026-10-02'],
            'date to text' => ['date', [], '2026-10-02', ['type' => 'text'], '2026-10-02'],
            'text to multi select' => ['text', [], 'a, b', ['type' => 'multiSelect'], ['a', 'b']],
            'multi select to text' => ['multiSelect', ['choices' => [['name' => 'a'], ['name' => 'b']]], ['a', 'b'], ['type' => 'text'], 'a, b'],
            'multi select to select' => ['multiSelect', ['choices' => [['name' => 'a'], ['name' => 'b']]], ['b', 'a'], ['type' => 'select'], 'b'],
            'checkbox to text' => ['checkbox', [], true, ['type' => 'text'], 'checked'],
            'text to person' => ['text', [], 'dana dev', ['type' => 'user'], 'me'],
            'text to formula' => ['text', [], 'gone', ['type' => 'formula', 'options' => ['formula' => '1 + 1']], 2],
            'number to rating' => ['number', [], 9, ['type' => 'rating', 'options' => ['max' => 5]], 5],
        ];
    }

    #[Test]
    #[DataProvider('conversions')]
    public function changing_a_fields_type_keeps_what_converts(string $from, array $options, mixed $value, array $change, mixed $expected): void
    {
        $key = $this->addField('deals', ['name' => 'Field', 'type' => $from, 'options' => $options]);
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$key => $value]);

        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", $change)->assertOk()->json('table');

        $this->assertSame($change['type'], collect($props['fields'])->firstWhere('key', $key)['type']);
        $this->assertSame($expected === 'me' ? $this->user->id : $expected, $this->recordIn($props, $deal->id)['values'][$key]);
    }

    #[Test]
    public function converting_to_a_select_makes_options_from_the_values(): void
    {
        $key = $this->addField('deals', ['name' => 'Field', 'type' => 'text']);
        foreach (['Red', 'Blue', 'Red', null] as $value) {
            $this->updateRecord('deals', $this->deal()->id, [$key => $value]);
        }

        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", ['type' => 'select'])->assertOk()->json('table');

        $this->assertSame([['name' => 'Red', 'color' => 'blue'], ['name' => 'Blue', 'color' => 'cyan']], collect($props['fields'])->firstWhere('key', $key)['options']['choices']);
        $this->assertSame(['Red', 'Blue', 'Red', null], array_map(fn (array $record) => $record['values'][$key], $props['records']));
    }

    #[Test]
    public function converting_to_a_link_matches_records_by_title(): void
    {
        $acme = $this->company(['name' => 'Acme']);
        $key = $this->addField('deals', ['name' => 'Field', 'type' => 'text']);
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$key => 'acme']);

        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", ['type' => 'link', 'options' => ['table' => 'companies']])->assertOk()->json('table');

        $this->assertSame([$acme->id], $this->recordIn($props, $deal->id)['values'][$key]);
    }

    #[Test]
    public function a_formula_turned_into_text_keeps_its_worked_out_values(): void
    {
        $deal = $this->deal(['value' => 1000, 'probability' => 0.5]);
        $key = $this->addField('deals', ['name' => 'Half', 'type' => 'formula', 'options' => ['formula' => '{Value} * {Probability}', 'format' => 'currency', 'symbol' => '$', 'precision' => 0]]);

        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", ['type' => 'text'])->assertOk()->json('table');

        $this->assertSame('$500', $this->recordIn($props, $deal->id)['values'][$key]);
        $this->assertSame(['type' => 'text', 'options' => []], array_intersect_key(collect($props['fields'])->firstWhere('key', $key), ['type' => 1, 'options' => 1]));
    }

    #[Test]
    public function renamed_options_are_kept_and_removed_ones_cleared(): void
    {
        $key = $this->addField('deals', ['name' => 'Size', 'type' => 'select', 'options' => ['choices' => [['name' => 'S'], ['name' => 'M'], ['name' => 'L']]]]);
        $tags = $this->addField('deals', ['name' => 'Labels', 'type' => 'multiSelect', 'options' => ['choices' => [['name' => 'a'], ['name' => 'b']]]]);
        $small = $this->deal();
        $large = $this->deal();
        $this->updateRecord('deals', $small->id, [$key => 'S', $tags => ['a', 'b']]);
        $this->updateRecord('deals', $large->id, [$key => 'L', $tags => ['b']]);

        $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", [
            'options' => ['choices' => [['name' => 'Small', 'color' => 'blue'], ['name' => 'M', 'color' => 'cyan']]],
            'renames' => ['S' => 'Small'],
        ])->assertOk();
        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$tags}", ['options' => ['choices' => [['name' => 'a']]]])->assertOk()->json('table');

        $this->assertSame('Small', $this->recordIn($props, $small->id)['values'][$key]);
        $this->assertNull($this->recordIn($props, $large->id)['values'][$key]);
        $this->assertSame(['a'], $this->recordIn($props, $small->id)['values'][$tags]);
        $this->assertSame([], $this->recordIn($props, $large->id)['values'][$tags]);
    }

    #[Test]
    public function field_changes_convert_every_record_not_just_the_visible_ones(): void
    {
        $key = $this->addField('deals', ['name' => 'Field', 'type' => 'text']);
        $hidden = $this->deal(['name' => 'Hidden']);
        $hidden->setCustomField($key, '42')->save();
        DealsTable::$scope = fn ($query) => $query->where('name', '!=', 'Hidden');

        $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$key}", ['type' => 'number'])->assertOk();

        $this->assertSame(42, Deal::query()->find($hidden->id)->customField($key));
    }
}
