<?php

namespace Tests\Feature\Tables;

use App\Models\TableView;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-006')]
class ViewsTest extends TablesTestCase
{
    #[Test]
    public function a_table_starts_with_the_views_it_declares(): void
    {
        $views = $this->props()['views'];

        $this->assertSame(['All deals', 'Pipeline', 'Close dates'], array_column($views, 'name'));
        $this->assertSame(['grid', 'board', 'calendar'], array_column($views, 'type'));
        $this->assertSame([['field' => 'close_date', 'direction' => 'asc']], $views[0]['config']['sorts']);
        $this->assertSame(['notes'], $views[0]['config']['hidden']);
        $this->assertSame('stage', $views[1]['config']['stackBy']);
        $this->assertSame('files', $views[1]['config']['coverField']);
        $this->assertSame('close_date', $views[2]['config']['dateField']);
        $this->assertFalse($views[0]['personal']);

        $this->props();
        $this->assertSame(3, TableView::query()->count());
    }

    #[Test]
    public function views_are_added_renamed_duplicated_and_deleted(): void
    {
        $view = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Won', 'type' => 'grid', 'config' => [
            'filters' => ['conditions' => [['id' => 'x', 'field' => 'stage', 'operator' => 'is', 'value' => 'Won']]],
            'unknown' => true,
        ]])->assertCreated()->json('view');

        $this->assertSame('Won', $view['name']);
        $this->assertSame(['conjunction' => 'and', 'conditions' => [['id' => 'x', 'field' => 'stage', 'operator' => 'is', 'value' => 'Won']]], $view['config']['filters']);
        $this->assertSame(['filters', 'sorts', 'groups', 'hidden', 'order', 'widths', 'rowHeight', 'summaries', 'stackBy', 'dateField', 'coverField'], array_keys($view['config']));

        $renamed = $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view['id']}", ['name' => 'Won deals', 'config' => ['rowHeight' => 'tall']])->assertOk()->json('view');
        $this->assertSame('Won deals', $renamed['name']);
        $this->assertSame('tall', $renamed['config']['rowHeight']);
        $this->assertSame('x', $renamed['config']['filters']['conditions'][0]['id']);

        $copy = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Copy', 'duplicate' => $view['id']])->assertCreated()->json('view');
        $this->assertSame('grid', $copy['type']);
        $this->assertSame($renamed['config'], $copy['config']);

        $this->actingAs($this->user)->deleteJson("/tables/deals/views/{$copy['id']}")->assertNoContent();
        $this->assertNull(TableView::query()->find($copy['id']));
    }

    #[Test]
    public function view_config_is_checked(): void
    {
        $this->props();
        $view = TableView::query()->first();

        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['config' => ['rowHeight' => 'huge']])
            ->assertUnprocessable()->assertJsonValidationErrors('config.rowHeight');
        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['config' => ['groups' => array_fill(0, 4, ['field' => 'stage'])]])
            ->assertUnprocessable()->assertJsonPath('errors', ['config.groups' => ['Views can be grouped by up to three fields.']]);
        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['config' => ['summaries' => ['value' => 'median']]])
            ->assertUnprocessable()->assertJsonValidationErrors('config.summaries.value');
        $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'X', 'type' => 'timeline'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $config = $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['config' => ['hidden' => ['nope', 'stage'], 'widths' => ['nope' => 100]]])
            ->assertOk()->json('view.config');
        $this->assertSame(['stage'], $config['hidden']);
        $this->assertSame([], $config['widths']);
    }

    #[Test]
    public function personal_views_are_only_seen_and_changed_by_their_creator(): void
    {
        $sam = User::factory()->create();
        $mine = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Mine', 'type' => 'grid', 'personal' => true])->assertCreated()->json('view');

        $this->assertTrue($mine['personal']);
        $this->assertContains('Mine', array_column($this->props()['views'], 'name'));
        $this->assertNotContains('Mine', array_column($this->actingAs($sam)->getJson('/tables/deals')->json('views'), 'name'));
        $this->actingAs($sam)->patchJson("/tables/deals/views/{$mine['id']}", ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($sam)->deleteJson("/tables/deals/views/{$mine['id']}")->assertNotFound();
    }

    #[Test]
    public function shared_views_need_permission_to_manage_views_but_personal_ones_dont(): void
    {
        $this->props();
        DealsTable::$denied = ['manageViews'];
        $view = TableView::query()->first();

        $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Shared', 'type' => 'grid'])->assertForbidden();
        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$view->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/tables/deals/views/{$view->id}")->assertForbidden();
        $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Mine', 'type' => 'grid', 'personal' => true])->assertCreated();
    }

    #[Test]
    public function the_last_shared_view_cant_be_deleted(): void
    {
        $this->props();
        $views = TableView::query()->orderBy('id')->get();

        $this->actingAs($this->user)->deleteJson("/tables/deals/views/{$views[0]->id}")->assertNoContent();
        $this->actingAs($this->user)->deleteJson("/tables/deals/views/{$views[1]->id}")->assertNoContent();
        $this->actingAs($this->user)->deleteJson("/tables/deals/views/{$views[2]->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['view' => ['The table\'s last shared view can\'t be deleted.']]);
    }

    #[Test]
    public function views_are_put_in_order(): void
    {
        $ids = array_column($this->props()['views'], 'id');

        $this->actingAs($this->user)->postJson('/tables/deals/views/order', ['ids' => array_reverse($ids)])->assertNoContent();

        $this->assertSame(array_reverse($ids), array_column($this->props()['views'], 'id'));
    }

    #[Test]
    public function views_of_another_table_arent_found(): void
    {
        $this->props();
        $this->props('companies');
        $companyView = TableView::query()->where('table', 'companies')->first();

        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$companyView->id}", ['name' => 'X'])->assertNotFound();
    }
}
