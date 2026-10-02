<?php

namespace Tests\Feature\Tables;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\Deal;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-001')]
class PermissionsTest extends TablesTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3?: array<string, mixed>}>
     */
    public static function refusals(): array
    {
        return [
            'view the table' => ['view', 'get', '/tables/deals'],
            'view records' => ['view', 'get', '/tables/deals/records?ids=1'],
            'create' => ['create', 'post', '/tables/deals/records', ['creates' => [['values' => ['name' => 'X']]]]],
            'update' => ['update', 'post', '/tables/deals/records', ['updates' => [['id' => 1, 'values' => ['name' => 'X']]]]],
            'delete' => ['delete', 'post', '/tables/deals/records', ['deletes' => [1]]],
            'add fields' => ['manageFields', 'post', '/tables/deals/fields', ['name' => 'X', 'type' => 'text']],
            'preview formulas' => ['manageFields', 'post', '/tables/deals/formula', ['formula' => '1']],
            'add shared views' => ['manageViews', 'post', '/tables/deals/views', ['name' => 'X', 'type' => 'grid']],
            'comment' => ['comment', 'post', '/tables/deals/records/1/comments', ['body' => 'Hi']],
            'upload' => ['update', 'post', '/tables/deals/attachments', []],
        ];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function each_ability_the_table_refuses_is_forbidden(string $ability, string $method, string $uri, array $data = []): void
    {
        $this->deal();
        DealsTable::$denied = $ability === 'update' && str_contains($uri, 'attachments') ? ['update', 'create'] : [$ability];

        $this->actingAs($this->user)->json($method, $uri, $data)->assertForbidden();
    }

    #[Test]
    public function permissions_are_sent_and_fields_are_read_only_without_update(): void
    {
        DealsTable::$denied = ['update', 'delete', 'comment'];

        $props = $this->props();

        $this->assertSame(['edit' => false, 'create' => true, 'delete' => false, 'manageFields' => true, 'manageViews' => true, 'comment' => false], $props['can']);
        $this->assertTrue(collect($props['fields'])->every(fn (array $field) => $field['readOnly']));
    }

    #[Test]
    public function people_only_see_and_change_the_records_query_returns(): void
    {
        $mine = $this->deal(['name' => 'Mine', 'owner_id' => $this->user->id]);
        $theirs = $this->deal(['name' => 'Theirs']);
        DealsTable::$scope = fn ($query, $user) => $query->where('owner_id', $user?->id);

        $this->assertSame([$mine->id], array_column($this->props()['records'], 'id'));
        $this->assertSame([], $this->actingAs($this->user)->getJson("/tables/deals/records?ids={$theirs->id}")->json('records'));

        $this->changes('deals', ['updates' => [['id' => $theirs->id, 'values' => ['name' => 'Taken']]]])->assertNotFound();
        $this->changes('deals', ['deletes' => [$theirs->id]])->assertOk()->assertJsonPath('deleted', []);
        $this->actingAs($this->user)->getJson("/tables/deals/records/{$theirs->id}/activity")->assertNotFound();

        $this->assertSame('Theirs', Deal::query()->find($theirs->id)->name);
    }
}
