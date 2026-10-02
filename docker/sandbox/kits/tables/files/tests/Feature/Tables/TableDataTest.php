<?php

namespace Tests\Feature\Tables;

use App\Tables\Tables;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('TABLE-001')]
class TableDataTest extends TablesTestCase
{
    #[Test]
    public function props_describe_the_table_its_fields_records_and_views(): void
    {
        $company = $this->company();
        $deal = $this->deal(['company_id' => $company->id, 'value' => 1200, 'probability' => 0.5, 'stage' => 'Won', 'tags' => ['Hot'], 'close_date' => '2026-10-02', 'owner_id' => $this->user->id, 'signed' => true]);

        $props = Tables::get('deals')->props($this->user);

        $this->assertSame('deals', $props['key']);
        $this->assertSame('Deals', $props['name']);
        $this->assertSame('/tables/deals', $props['endpoint']);
        $this->assertSame($this->user->id, $props['me']);
        $this->assertFalse($props['truncated']);
        $this->assertSame(['edit' => true, 'create' => true, 'delete' => true, 'manageFields' => true, 'manageViews' => true, 'comment' => true], $props['can']);
        $this->assertSame([['id' => $this->user->id, 'name' => 'Dana Dev', 'email' => 'dana@example.com', 'avatar' => null]], $props['users']);

        $fields = collect($props['fields'])->keyBy('key');
        $this->assertSame(['key' => 'name', 'name' => 'Name', 'type' => 'text', 'description' => null, 'builtIn' => true, 'primary' => true, 'readOnly' => false], array_diff_key($fields['name'], ['options' => 1]));
        $this->assertSame(['precision' => 0, 'symbol' => '$'], (array) $fields['value']['options']);
        $this->assertSame(['table' => 'companies', 'multiple' => false, 'showInverse' => false], (array) $fields['company_id']['options']);
        $this->assertTrue($fields['contacts']['options']->multiple);
        $this->assertSame('{Value} * {Probability}', $fields['weighted']['options']->formula);
        $this->assertTrue($fields['weighted']['readOnly']);
        $this->assertTrue($fields['code']['readOnly']);
        $this->assertSame(['type' => 'text', 'options' => []], ['type' => $fields['company_city']['options']->result['type'], 'options' => (array) $fields['company_city']['options']->result['options']]);

        $record = $props['records'][0];
        $this->assertSame($deal->id, $record['id']);
        $values = (array) $record['values'];
        $this->assertSame([$company->id], $values['company_id']);
        $this->assertSame(1200, $values['value']);
        $this->assertSame(0.5, $values['probability']);
        $this->assertSame('Won', $values['stage']);
        $this->assertSame(['Hot'], $values['tags']);
        $this->assertSame('2026-10-02', $values['close_date']);
        $this->assertSame($this->user->id, $values['owner_id']);
        $this->assertTrue($values['signed']);
        $this->assertSame([], $values['files']);
        $this->assertSame(600, $values['weighted']);
        $this->assertSame(['Montreal'], $values['company_city']);
        $this->assertSame([], $values['contacts']);
        $this->assertNotNull($record['createdAt']);

        $this->assertSame(['companies', 'contacts'], array_keys((array) $props['linked']));
        $this->assertSame(['name' => 'Companies', 'records' => [['id' => $company->id, 'title' => 'Acme']]], ((array) $props['linked'])['companies']);

        $this->assertSame(['All deals', 'Pipeline', 'Close dates'], array_column($props['views'], 'name'));
        $this->assertSame(['deals', 'companies', 'contacts', 'notes'], array_column($props['tables'], 'key'));
        $this->assertContains(['key' => 'stage', 'name' => 'Stage', 'type' => 'select'], $props['tables'][0]['fields']);
    }

    #[Test]
    public function empty_objects_are_sent_as_json_objects(): void
    {
        $this->company(['name' => '']);

        $json = $this->actingAs($this->user)->getJson('/tables/companies')->assertOk()->getContent();

        $this->assertStringContainsString('"widths":{}', $json);
        $this->assertStringContainsString('"summaries":{}', $json);
        $this->assertStringContainsString('"errors":{}', $json);
        $this->assertStringContainsString('"key":"name","name":"Name","type":"text","description":null,"builtIn":true,"primary":true,"readOnly":false,"options":{}', $json);
        $this->assertStringContainsString('"title":"Untitled"', $this->actingAs($this->user)->getJson('/tables/deals')->getContent());

        $notes = $this->actingAs($this->user)->getJson('/tables/notes')->assertOk()->getContent();
        $this->assertStringContainsString('"linked":{}', $notes);
        $this->assertStringContainsString('"records":[]', $notes);
    }

    #[Test]
    public function values_of_an_empty_record_are_empty_for_their_type(): void
    {
        $deal = $this->deal(['name' => null]);
        $fieldKey = $this->addField('deals', ['name' => 'Done', 'type' => 'checkbox']);
        $tagsKey = $this->addField('deals', ['name' => 'Labels', 'type' => 'multiSelect']);

        $values = $this->recordIn($this->props(), $deal->id)['values'];

        $this->assertFalse($values[$fieldKey]);
        $this->assertSame([], $values[$tagsKey]);
        $this->assertNull($values['name']);
        $this->assertFalse($values['signed']);
        $this->assertSame([], $values['tags']);
        $this->assertSame([], $values['company_city']);
    }

    #[Test]
    public function records_are_capped_at_max_rows(): void
    {
        config(['tables.max_rows' => 2]);
        $first = $this->deal();
        $second = $this->deal();
        $this->deal();

        $props = $this->props();

        $this->assertTrue($props['truncated']);
        $this->assertSame([$first->id, $second->id], array_column($props['records'], 'id'));
    }

    #[Test]
    public function records_are_fetched_by_id(): void
    {
        $first = $this->deal(['name' => 'One']);
        $this->deal(['name' => 'Two']);
        $third = $this->deal(['name' => 'Three']);

        $records = $this->actingAs($this->user)->getJson("/tables/deals/records?ids={$third->id},{$first->id}")->assertOk()->json('records');

        $this->assertSame([$first->id, $third->id], array_column($records, 'id'));
        $this->assertSame('Three', $records[1]['values']['name']);
    }

    #[Test]
    public function unknown_tables_are_not_found(): void
    {
        $this->actingAs($this->user)->getJson('/tables/nope')->assertNotFound();
        $this->actingAs($this->user)->postJson('/tables/nope/records', [])->assertNotFound();
    }

    #[Test]
    public function guests_are_turned_away(): void
    {
        $this->getJson('/tables/deals')->assertUnauthorized();
    }
}
