<?php

namespace Tests\Feature\Tables;

use App\Models\TableActivity;
use App\Models\TableField;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\Deal;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-001')]
#[Group('TABLE-002')]
class RecordChangesTest extends TablesTestCase
{
    #[Test]
    public function creates_updates_and_deletes_go_through_in_one_batch(): void
    {
        $kept = $this->deal(['name' => 'Kept']);
        $gone = $this->deal(['name' => 'Gone']);

        $response = $this->changes('deals', [
            'creates' => [['values' => ['name' => 'New one', 'value' => '$1,200']], ['values' => []]],
            'updates' => [['id' => $kept->id, 'values' => ['stage' => 'won']]],
            'deletes' => [$gone->id],
        ])->assertOk();

        $this->assertCount(3, $response->json('records'));
        $this->assertSame([$gone->id], $response->json('deleted'));
        $this->assertSame('New one', $response->json('records.0.values.name'));
        $this->assertSame(1200, $response->json('records.0.values.value'));
        $this->assertSame('Won', $kept->fresh()->stage);
        $this->assertNull(Deal::query()->find($gone->id));
        $this->assertSame(3, Deal::query()->count());
    }

    #[Test]
    public function nothing_is_saved_when_any_value_is_invalid(): void
    {
        $deal = $this->deal(['name' => 'Before']);

        $this->changes('deals', [
            'creates' => [['values' => ['name' => 'New', 'value' => 'abc']]],
            'updates' => [['id' => $deal->id, 'values' => ['name' => 'After', 'stage' => 'Done']]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors', [
                'new.0.value' => ['“abc” isn\'t a number.'],
                "{$deal->id}.stage" => ['“Done” isn\'t an option for Stage.'],
            ]);

        $this->assertSame('Before', $deal->fresh()->name);
        $this->assertSame(1, Deal::query()->count());
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed}>
     */
    public static function lenientValues(): array
    {
        return [
            'currency with symbol and commas' => ['value', '$1,200.50', 1200.5],
            'negative in brackets' => ['value', '(30)', -30],
            'percent from text' => ['probability', '25%', 0.25],
            'percent as a fraction' => ['probability', 0.4, 0.4],
            'number from text' => ['seats', ' 12 ', 12],
            'checkbox from yes' => ['signed', 'yes', true],
            'checkbox from x' => ['signed', 'x', true],
            'checkbox from 1' => ['signed', '1', true],
            'checkbox from no' => ['signed', 'no', false],
            'checkbox from null' => ['signed', null, false],
            'date from ISO' => ['close_date', '2026-10-02', '2026-10-02'],
            'date from US format' => ['close_date', '10/2/2026', '2026-10-02'],
            'date from words' => ['close_date', 'Oct 2, 2026', '2026-10-02'],
            'date from ISO with offset' => ['close_date', '2026-10-02T23:30:00-04:00', '2026-10-02'],
            'select ignoring case' => ['stage', 'qualified', 'Qualified'],
            'multi select from text' => ['tags', 'hot, renewal', ['Hot', 'Renewal']],
            'multi select from a list' => ['tags', ['Renewal', 'Renewal'], ['Renewal']],
            'rating' => ['fit', 4, 4],
            'text' => ['notes', "Line one\nLine two", "Line one\nLine two"],
            'email' => ['email', ' sam@example.com ', 'sam@example.com'],
            'empty text' => ['website', '', null],
        ];
    }

    #[Test]
    #[DataProvider('lenientValues')]
    public function values_are_read_leniently(string $field, mixed $input, mixed $expected): void
    {
        $deal = $this->deal();

        $record = $this->updateRecord('deals', $deal->id, [$field => $input]);

        $this->assertSame($expected, $record['values'][$field]);
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'number' => ['value', 'abc', '“abc” isn\'t a number.'],
            'checkbox' => ['signed', 'maybe', '“maybe” isn\'t yes or no.'],
            'date' => ['close_date', 'someday', '“someday” isn\'t a date.'],
            'select' => ['stage', 'Done', '“Done” isn\'t an option for Stage.'],
            'multi select' => ['tags', ['Hot', 'Cold'], '“Cold” isn\'t an option for Tags.'],
            'rating too high' => ['fit', 6, 'Fit takes a whole number from 0 to 5.'],
            'rating not whole' => ['fit', 2.5, 'Fit takes a whole number from 0 to 5.'],
            'email' => ['email', 'not an email', '“not an email” isn\'t an email address.'],
            'person' => ['owner_id', 'Nobody', 'No one called “Nobody” can be picked for Owner.'],
            'person id' => ['owner_id', 999, 'That person can\'t be picked for Owner.'],
            'link title' => ['company_id', 'Initech', '“Initech” isn\'t a record in Companies.'],
            'link id' => ['company_id', [999], 'There\'s no record 999 in Companies.'],
            'formula' => ['weighted', 5, 'Weighted value is worked out from other fields.'],
            'lookup' => ['company_city', 'X', 'Company city is worked out from other fields.'],
            'created at' => ['created_at', '2026-01-01', 'Created is set automatically.'],
            'read-only' => ['code', 'X', 'Code can\'t be changed.'],
            'attachment from elsewhere' => ['files', [['key' => 'other/file.png', 'name' => 'file.png']], 'Files has a file that wasn\'t uploaded here.'],
            'unknown field' => ['nope', 'X', 'There\'s no field “nope”.'],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function values_that_dont_fit_are_refused_with_the_reason(string $field, mixed $input, string $message): void
    {
        $deal = $this->deal();

        $this->changes('deals', ['updates' => [['id' => $deal->id, 'values' => [$field => $input]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["{$deal->id}.{$field}" => [$message]]);
    }

    #[Test]
    public function people_are_picked_by_id_email_or_name(): void
    {
        $sam = User::factory()->create(['name' => 'Sam Member', 'email' => 'sam@example.com']);
        $deal = $this->deal();

        $this->assertSame($sam->id, $this->updateRecord('deals', $deal->id, ['owner_id' => $sam->id])['values']['owner_id']);
        $this->assertSame($this->user->id, $this->updateRecord('deals', $deal->id, ['owner_id' => 'DANA@example.com'])['values']['owner_id']);
        $this->assertSame($sam->id, $this->updateRecord('deals', $deal->id, ['owner_id' => 'sam member'])['values']['owner_id']);
        $this->assertNull($this->updateRecord('deals', $deal->id, ['owner_id' => null])['values']['owner_id']);
    }

    #[Test]
    public function date_and_time_fields_keep_the_time(): void
    {
        $key = $this->addField('deals', ['name' => 'Meeting', 'type' => 'date', 'options' => ['includeTime' => true]]);
        $deal = $this->deal();

        $record = $this->updateRecord('deals', $deal->id, [$key => '2026-10-02T09:30:00-04:00']);

        $this->assertSame('2026-10-02T09:30:00-04:00', $record['values'][$key]);
    }

    #[Test]
    public function new_records_get_the_tables_defaults_and_go_through_saving(): void
    {
        $record = $this->changes('deals', ['creates' => [['values' => ['name' => 'shout']]]])->assertOk()->json('records.0');

        $this->assertSame('SHOUT', $record['values']['name']);
        $this->assertSame('Lead', $record['values']['stage']);
        $this->assertSame($this->user->id, $record['values']['owner_id']);
        $this->assertSame(['shout'], DealsTable::$saved);

        $this->updateRecord('deals', $record['id'], ['name' => 'Quiet']);
        $this->assertSame(['shout', 'Quiet'], DealsTable::$saved);
    }

    #[Test]
    public function typing_a_new_option_adds_it_to_a_field_people_added(): void
    {
        $key = $this->addField('deals', ['name' => 'Priority', 'type' => 'select', 'options' => ['choices' => [['name' => 'High', 'color' => 'blue']]]]);
        $multiKey = $this->addField('deals', ['name' => 'Labels', 'type' => 'multiSelect']);
        $deal = $this->deal();

        $record = $this->updateRecord('deals', $deal->id, [$key => 'Urgent', $multiKey => 'a, b']);

        $this->assertSame('Urgent', $record['values'][$key]);
        $this->assertSame(['a', 'b'], $record['values'][$multiKey]);
        $field = TableField::query()->find(TableField::idFromKey($key));
        $this->assertSame([['name' => 'High', 'color' => 'blue'], ['name' => 'Urgent', 'color' => 'cyan']], $field->options['choices']);
        $this->assertSame(['a', 'b'], array_column(TableField::query()->find(TableField::idFromKey($multiKey))->options['choices'], 'name'));
    }

    #[Test]
    public function new_options_need_permission_to_change_fields(): void
    {
        $key = $this->addField('deals', ['name' => 'Priority', 'type' => 'select']);
        DealsTable::$denied = ['manageFields'];
        $deal = $this->deal();

        $this->changes('deals', ['updates' => [['id' => $deal->id, 'values' => [$key => 'Urgent']]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["{$deal->id}.{$key}" => ['“Urgent” isn\'t an option for Priority.']]);
    }

    #[Test]
    public function every_changed_field_is_kept_in_the_history_as_text(): void
    {
        $deal = $this->deal(['name' => 'Old', 'value' => 100, 'stage' => 'Lead']);

        $this->updateRecord('deals', $deal->id, ['name' => 'New', 'value' => 2500, 'stage' => 'Lead', 'owner_id' => $this->user->id]);

        $changes = TableActivity::query()->where('record_id', $deal->id)->orderBy('id')->get(['kind', 'field', 'field_name', 'from', 'to'])->toArray();

        $this->assertSame([
            ['kind' => 'updated', 'field' => 'name', 'field_name' => 'Name', 'from' => 'Old', 'to' => 'New'],
            ['kind' => 'updated', 'field' => 'value', 'field_name' => 'Value', 'from' => '$100', 'to' => '$2,500'],
            ['kind' => 'updated', 'field' => 'owner_id', 'field_name' => 'Owner', 'from' => null, 'to' => 'Dana Dev'],
        ], $changes);
    }

    #[Test]
    public function creating_a_record_is_kept_in_the_history(): void
    {
        $id = $this->changes('deals', ['creates' => [['values' => ['name' => 'New']]]])->json('records.0.id');

        $this->assertSame(['created'], TableActivity::query()->where('record_id', $id)->pluck('kind')->all());
    }

    #[Test]
    public function the_request_must_be_shaped_like_record_changes(): void
    {
        $this->changes('deals', ['updates' => [['values' => ['name' => 'X']]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('updates.0.id');
    }
}
