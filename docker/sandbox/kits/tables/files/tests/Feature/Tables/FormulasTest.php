<?php

namespace Tests\Feature\Tables;

use App\Models\TableField;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('TABLE-004')]
class FormulasTest extends TablesTestCase
{
    #[Test]
    public function formulas_work_out_values_from_the_records_fields(): void
    {
        $deal = $this->deal(['value' => 1000, 'probability' => 0.25, 'close_date' => '2026-10-02']);
        $late = $this->addField('deals', ['name' => 'Status', 'type' => 'formula', 'options' => ['formula' => 'IF({Close date} < DATETIME_PARSE("2026-11-01"), "Soon", "Later") & " " & {Name}']]);
        $next = $this->addField('deals', ['name' => 'Follow up', 'type' => 'formula', 'options' => ['formula' => 'DATEADD({Close date}, 7, "days")', 'format' => 'date']]);

        $values = $this->recordIn($this->props(), $deal->id)['values'];

        $this->assertSame(250, $values['weighted']);
        $this->assertSame('Soon Big deal', $values[$late]);
        $this->assertSame('2026-10-09', $values[$next]);
    }

    #[Test]
    public function formulas_can_use_other_formulas_lookups_and_rollups(): void
    {
        $acme = $this->company(['city' => 'Montreal']);
        $deal = $this->deal(['company_id' => $acme->id, 'value' => 1000, 'probability' => 0.5]);
        $double = $this->addField('deals', ['name' => 'Double', 'type' => 'formula', 'options' => ['formula' => '{Weighted value} * 2']]);
        $where = $this->addField('deals', ['name' => 'Where', 'type' => 'formula', 'options' => ['formula' => 'ARRAYJOIN({Company city}) & "!"']]);
        $companyTotal = $this->addField('companies', ['name' => 'Twice total', 'type' => 'formula', 'options' => ['formula' => '{Total} * 2']]);

        $this->assertSame(1000, $this->recordIn($this->props(), $deal->id)['values'][$double]);
        $this->assertSame('Montreal!', $this->recordIn($this->props(), $deal->id)['values'][$where]);
        $this->assertSame(2000, $this->recordIn($this->props('companies'), $acme->id)['values'][$companyTotal]);
    }

    #[Test]
    public function a_formula_that_cant_be_worked_out_for_a_record_shows_why(): void
    {
        $ok = $this->deal(['value' => 100, 'seats' => 4]);
        $broken = $this->deal(['value' => 100, 'seats' => 0]);
        $key = $this->addField('deals', ['name' => 'Per seat', 'type' => 'formula', 'options' => ['formula' => '{Value} / {Seats}']]);
        $uses = $this->addField('deals', ['name' => 'Uses it', 'type' => 'formula', 'options' => ['formula' => '{Per seat} + 1']]);

        $props = $this->props();

        $this->assertSame(25, $this->recordIn($props, $ok->id)['values'][$key]);
        $this->assertSame([], (array) $this->recordIn($props, $ok->id)['errors']);
        $this->assertNull($this->recordIn($props, $broken->id)['values'][$key]);
        $this->assertSame([$key => "Can't divide by zero", $uses => "Can't divide by zero"], (array) $this->recordIn($props, $broken->id)['errors']);
    }

    #[Test]
    public function a_formula_keeps_working_when_a_field_it_uses_is_renamed(): void
    {
        $deal = $this->deal(['value' => 10]);
        $price = $this->addField('deals', ['name' => 'Price', 'type' => 'number']);
        $total = $this->addField('deals', ['name' => 'Total', 'type' => 'formula', 'options' => ['formula' => '{Price} * {Value}']]);
        $this->updateRecord('deals', $deal->id, [$price => 3]);

        $this->assertSame('{'.$price.'} * {value}', TableField::query()->find(TableField::idFromKey($total))->options['formula']);

        $props = $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$price}", ['name' => 'Unit price'])->assertOk()->json('table');

        $this->assertSame('{Unit price} * {Value}', collect($props['fields'])->firstWhere('key', $total)['options']['formula']);
        $this->assertSame(30, $this->recordIn($props, $deal->id)['values'][$total]);
    }

    #[Test]
    public function a_formula_cant_refer_to_itself(): void
    {
        $first = $this->addField('deals', ['name' => 'First', 'type' => 'formula', 'options' => ['formula' => '{Value} + 1']]);
        $second = $this->addField('deals', ['name' => 'Second', 'type' => 'formula', 'options' => ['formula' => '{First} + 1']]);

        $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$first}", ['options' => ['formula' => '{First} + 1']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.formula' => ['This formula refers to itself.']]);

        $this->actingAs($this->user)->patchJson("/tables/deals/fields/{$first}", ['options' => ['formula' => '{Second} * 2']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.formula' => ['This formula refers to itself.']]);

        $this->assertNotNull($second);
    }

    #[Test]
    public function a_formula_cant_refer_to_itself_through_a_linked_table(): void
    {
        $acme = $this->company();
        $this->deal(['company_id' => $acme->id]);
        $formula = $this->addField('companies', ['name' => 'Label', 'type' => 'formula', 'options' => ['formula' => '{Name}']]);
        $label = $this->addField('deals', ['name' => 'Company label', 'type' => 'lookup', 'options' => ['link' => 'company_id', 'field' => $formula]]);
        $back = $this->addField('companies', ['name' => 'Deal labels', 'type' => 'lookup', 'options' => ['link' => 'deals', 'field' => $label]]);

        $this->actingAs($this->user)->patchJson("/tables/companies/fields/{$formula}", ['options' => ['formula' => 'ARRAYJOIN({Deal labels})']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.formula' => ['This formula refers to itself.']]);

        $this->assertNotNull($back);
    }

    #[Test]
    public function formula_errors_are_explained_before_saving(): void
    {
        $this->actingAs($this->user)->postJson('/tables/deals/fields', ['name' => 'Bad', 'type' => 'formula', 'options' => ['formula' => '{Nope} + 1']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.formula' => ['Unknown field {Nope}']]);

        $this->actingAs($this->user)->postJson('/tables/deals/fields', ['name' => 'Bad', 'type' => 'formula', 'options' => ['formula' => '']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.formula' => ['Write a formula.']]);
    }

    #[Test]
    public function a_formula_is_previewed_on_the_first_records(): void
    {
        $deals = collect(range(1, 6))->map(fn (int $number) => $this->deal(['name' => "Deal {$number}", 'value' => $number * 10, 'seats' => $number === 2 ? 0 : 1]));

        $preview = $this->actingAs($this->user)->postJson('/tables/deals/formula', ['formula' => '{Value} / {Seats}'])->assertOk()->json();

        $this->assertTrue($preview['ok']);
        $this->assertCount(5, $preview['results']);
        $this->assertSame(['id' => $deals[0]->id, 'title' => 'Deal 1', 'value' => 10, 'error' => null], $preview['results'][0]);
        $this->assertSame("Can't divide by zero", $preview['results'][1]['error']);

        $this->actingAs($this->user)->postJson('/tables/deals/formula', ['formula' => '{Value} +'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['ok', 'error']);
    }
}
