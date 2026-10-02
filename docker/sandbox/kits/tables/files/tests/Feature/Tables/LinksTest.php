<?php

namespace Tests\Feature\Tables;

use App\Models\TableField;
use App\Tables\Tables;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\Deal;

#[Group('TABLE-005')]
class LinksTest extends TablesTestCase
{
    #[Test]
    public function a_belongs_to_link_is_set_by_id_or_title_and_holds_one_record(): void
    {
        $acme = $this->company(['name' => 'Acme']);
        $initech = $this->company(['name' => 'Initech']);
        $deal = $this->deal();

        $this->assertSame([$initech->id], $this->updateRecord('deals', $deal->id, ['company_id' => 'initech'])['values']['company_id']);
        $this->assertSame([$acme->id], $this->updateRecord('deals', $deal->id, ['company_id' => [$acme->id]])['values']['company_id']);
        $this->assertSame($acme->id, (int) $deal->fresh()->company_id);

        $this->changes('deals', ['updates' => [['id' => $deal->id, 'values' => ['company_id' => [$acme->id, $initech->id]]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["{$deal->id}.company_id" => ['Company can link only one record.']]);

        $this->assertSame([], $this->updateRecord('deals', $deal->id, ['company_id' => []])['values']['company_id']);
        $this->assertNull($deal->fresh()->company_id);
    }

    #[Test]
    public function a_belongs_to_many_link_is_synced_through_the_relation(): void
    {
        $chris = $this->contact(['name' => 'Chris']);
        $robin = $this->contact(['name' => 'Robin']);
        $deal = $this->deal();

        $record = $this->updateRecord('deals', $deal->id, ['contacts' => [$chris->id, 'Robin']]);

        $this->assertSame([$chris->id, $robin->id], $record['values']['contacts']);
        $this->assertSame([$chris->id, $robin->id], $deal->contacts()->orderBy('kit_test_contacts.id')->pluck('kit_test_contacts.id')->all());

        $this->updateRecord('deals', $deal->id, ['contacts' => [$robin->id]]);
        $this->assertSame([$robin->id], $deal->contacts()->pluck('kit_test_contacts.id')->all());
    }

    #[Test]
    public function linked_from_shows_the_records_linking_to_each_record(): void
    {
        $acme = $this->company();
        $one = $this->deal(['company_id' => $acme->id, 'value' => 100]);
        $two = $this->deal(['company_id' => $acme->id, 'value' => 250]);
        $this->deal(['value' => 999]);

        $values = $this->recordIn($this->props('companies'), $acme->id)['values'];

        $this->assertSame([$one->id, $two->id], $values['deals']);
        $this->assertSame(350, $values['total']);
        $this->assertSame(2, $values['deal_count']);
    }

    #[Test]
    public function linked_from_is_read_only(): void
    {
        $acme = $this->company();

        $this->changes('companies', ['updates' => [['id' => $acme->id, 'values' => ['deals' => []]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["{$acme->id}.deals" => ['Deals is changed from the other table.']]);
    }

    #[Test]
    public function a_link_people_add_holds_several_records_and_can_show_in_the_other_table(): void
    {
        $key = $this->addField('companies', ['name' => 'Partners', 'type' => 'link', 'options' => ['table' => 'contacts', 'showInverse' => true]]);
        $acme = $this->company();
        $chris = $this->contact(['name' => 'Chris']);
        $robin = $this->contact(['name' => 'Robin']);

        $record = $this->updateRecord('companies', $acme->id, [$key => 'Chris, Robin']);
        $this->assertSame([$chris->id, $robin->id], $record['values'][$key]);
        $this->assertSame([$chris->id, $robin->id], $acme->fresh()->customField($key));

        $contacts = $this->props('contacts');
        $inverse = collect($contacts['fields'])->firstWhere('name', 'Companies');
        $this->assertSame('link', $inverse['type']);
        $this->assertTrue($inverse['readOnly']);
        $this->assertFalse($inverse['builtIn']);
        $this->assertSame(['table' => 'companies', 'multiple' => true, 'inverse' => true, 'source' => $key], (array) $inverse['options']);
        $this->assertSame([$acme->id], $this->recordIn($contacts, $chris->id)['values'][$inverse['key']]);
        $this->assertSame('Acme', ((array) $contacts['linked'])['companies']['records'][0]['title']);
    }

    #[Test]
    public function deleting_a_link_deletes_its_other_side_and_deleting_the_other_side_turns_it_off(): void
    {
        $key = $this->addField('companies', ['name' => 'Partners', 'type' => 'link', 'options' => ['table' => 'contacts', 'showInverse' => true]]);
        $inverseKey = collect($this->props('contacts')['fields'])->firstWhere('name', 'Companies')['key'];

        $this->actingAs($this->user)->deleteJson("/tables/contacts/fields/{$inverseKey}")->assertOk();
        $this->assertFalse(TableField::query()->find(TableField::idFromKey($key))->options['showInverse']);

        $this->actingAs($this->user)->patchJson("/tables/companies/fields/{$key}", ['options' => ['showInverse' => true]])->assertOk();
        $this->assertSame(1, TableField::query()->where('table', 'contacts')->count());

        $this->actingAs($this->user)->deleteJson("/tables/companies/fields/{$key}")->assertOk();
        $this->assertSame(0, TableField::query()->count());
    }

    #[Test]
    public function deleting_a_record_clears_links_to_it(): void
    {
        $key = $this->addField('deals', ['name' => 'Related', 'type' => 'link', 'options' => ['table' => 'companies']]);
        $acme = $this->company();
        $initech = $this->company(['name' => 'Initech']);
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$key => [$acme->id, $initech->id]]);

        $this->changes('companies', ['deletes' => [$acme->id]])->assertOk();

        $this->assertSame([$initech->id], $deal->fresh()->customField($key));
    }

    #[Test]
    public function lookups_show_the_linked_records_values(): void
    {
        $acme = $this->company(['city' => 'Montreal']);
        $deal = $this->deal(['company_id' => $acme->id]);
        $key = $this->addField('companies', ['name' => 'Deal stages', 'type' => 'lookup', 'options' => ['link' => 'deals', 'field' => 'stage']]);
        $this->deal(['company_id' => $acme->id, 'stage' => 'Won']);
        $this->deal(['company_id' => $acme->id, 'stage' => 'Lost']);

        $props = $this->props('companies');
        $this->assertSame(['Won', 'Lost'], $this->recordIn($props, $acme->id)['values'][$key]);
        $field = collect($props['fields'])->firstWhere('key', $key);
        $this->assertSame('select', $field['options']['result']['type']);
        $this->assertSame('Lead', $field['options']['result']['options']['choices'][0]['name']);

        $this->assertSame(['Montreal'], $this->recordIn($this->props(), $deal->id)['values']['company_city']);
    }

    #[Test]
    public function lookups_of_links_and_people_show_titles_and_names(): void
    {
        $acme = $this->company();
        $this->deal(['company_id' => $acme->id, 'owner_id' => $this->user->id]);
        $owners = $this->addField('companies', ['name' => 'Owners', 'type' => 'lookup', 'options' => ['link' => 'deals', 'field' => 'owner_id']]);
        $names = $this->addField('companies', ['name' => 'Deal names', 'type' => 'lookup', 'options' => ['link' => 'deals', 'field' => 'name']]);

        $props = $this->props('companies');

        $this->assertSame(['Dana Dev'], $this->recordIn($props, $acme->id)['values'][$owners]);
        $this->assertSame(['Big deal'], $this->recordIn($props, $acme->id)['values'][$names]);
        $this->assertSame('text', collect($props['fields'])->firstWhere('key', $owners)['options']['result']['type']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function rollups(): array
    {
        return [
            'sum' => ['sum', 'value', 350],
            'average' => ['average', 'value', 175],
            'min' => ['min', 'value', 100],
            'max' => ['max', 'value', 250],
            'count of filled values' => ['count', 'stage', 1],
            'count of linked records' => ['countAll', 'stage', 2],
            'join' => ['join', 'name', 'First, Second'],
            'earliest' => ['earliest', 'close_date', '2026-01-15'],
            'latest' => ['latest', 'close_date', '2026-03-01'],
        ];
    }

    #[Test]
    #[DataProvider('rollups')]
    public function rollups_sum_up_the_linked_records(string $function, string $field, mixed $expected): void
    {
        $acme = $this->company();
        $this->deal(['name' => 'First', 'company_id' => $acme->id, 'value' => 100, 'stage' => 'Won', 'close_date' => '2026-03-01']);
        $this->deal(['name' => 'Second', 'company_id' => $acme->id, 'value' => 250, 'close_date' => '2026-01-15']);
        $this->deal(['name' => 'Other', 'value' => 1000]);

        $key = $this->addField('companies', ['name' => 'Rolled up', 'type' => 'rollup', 'options' => ['link' => 'deals', 'field' => $field, 'function' => $function]]);

        $this->assertSame($expected, $this->recordIn($this->props('companies'), $acme->id)['values'][$key]);
    }

    #[Test]
    public function counts_count_the_linked_records(): void
    {
        $chris = $this->contact();
        $robin = $this->contact(['name' => 'Robin']);
        $deal = $this->deal();
        $deal->contacts()->attach([$chris->id, $robin->id]);
        $key = $this->addField('deals', ['name' => 'People', 'type' => 'count', 'options' => ['link' => 'contacts']]);

        $this->assertSame(2, $this->recordIn($this->props(), $deal->id)['values'][$key]);
    }

    #[Test]
    public function lookups_and_rollups_need_a_link_field_and_a_field_of_the_linked_table(): void
    {
        $this->actingAs($this->user)->postJson('/tables/companies/fields', ['name' => 'X', 'type' => 'lookup', 'options' => ['link' => 'city', 'field' => 'name']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.link' => ['“city” isn\'t a link field in Companies.']]);

        $this->actingAs($this->user)->postJson('/tables/companies/fields', ['name' => 'X', 'type' => 'rollup', 'options' => ['link' => 'deals', 'field' => 'nope']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.field' => ['Choose a field of Deals.']]);

        $this->actingAs($this->user)->postJson('/tables/companies/fields', ['name' => 'X', 'type' => 'rollup', 'options' => ['link' => 'deals', 'field' => 'value', 'function' => 'median']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.function' => ['“median” isn\'t a rollup function.']]);

        $this->actingAs($this->user)->postJson('/tables/companies/fields', ['name' => 'X', 'type' => 'link', 'options' => ['table' => 'planets']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['options.table' => ['There\'s no table “planets”.']]);
    }

    #[Test]
    public function linked_values_are_worked_out_with_a_query_per_table(): void
    {
        $companies = collect(range(1, 20))->map(fn (int $number) => $this->company(['name' => "Company {$number}"]));
        $contact = $this->contact();

        foreach (range(1, 100) as $number) {
            $deal = $this->deal(['name' => "Deal {$number}", 'company_id' => $companies->random()->id, 'value' => $number]);
            $deal->contacts()->attach($contact->id);
        }

        $this->actingAs($this->user);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $props = Tables::get('deals')->props($this->user);

        $this->assertCount(100, $props['records']);
        $this->assertLessThan(25, $queries, "Queries shouldn't grow with the number of records.");
        $this->assertSame(100, Deal::query()->count());
    }
}
