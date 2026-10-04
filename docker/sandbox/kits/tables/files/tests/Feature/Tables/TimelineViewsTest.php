<?php

namespace Tests\Feature\Tables;

use App\Tables\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-010')]
class TimelineViewsTest extends TablesTestCase
{
    #[Test]
    public function a_timeline_starts_on_the_first_date_field_by_week(): void
    {
        $config = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Roadmap', 'type' => 'timeline'])
            ->assertCreated()->json('view.config');

        $this->assertSame('close_date', $config['dateField']);
        $this->assertNull($config['endField']);
        $this->assertSame('week', $config['timelineScale']);
        $this->assertNull($config['form']);
    }

    #[Test]
    public function a_timeline_ends_on_a_date_field_and_has_a_scale(): void
    {
        $id = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Roadmap', 'type' => 'timeline'])->json('view.id');

        $config = $this->actingAs($this->user)->patchJson("/tables/deals/views/{$id}", ['config' => ['endField' => 'created_at', 'timelineScale' => 'month']])
            ->assertOk()->json('view.config');
        $this->assertSame('created_at', $config['endField']);
        $this->assertSame('month', $config['timelineScale']);

        $this->assertNull($this->actingAs($this->user)->patchJson("/tables/deals/views/{$id}", ['config' => ['endField' => 'stage']])->json('view.config.endField'));
        $this->assertNull($this->actingAs($this->user)->patchJson("/tables/deals/views/{$id}", ['config' => ['endField' => 'nope']])->json('view.config.endField'));

        $this->actingAs($this->user)->patchJson("/tables/deals/views/{$id}", ['config' => ['timelineScale' => 'year']])
            ->assertUnprocessable()->assertJsonValidationErrors('config.timelineScale');
    }

    #[Test]
    public function deleting_the_end_field_takes_it_out_of_timelines(): void
    {
        $end = $this->addField('deals', ['name' => 'Ends', 'type' => 'date']);
        $id = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Roadmap', 'type' => 'timeline', 'config' => ['endField' => $end]])
            ->assertCreated()->json('view.id');

        $this->assertSame($end, collect($this->props()['views'])->firstWhere('id', $id)['config']['endField']);

        $this->actingAs($this->user)->deleteJson("/tables/deals/fields/{$end}")->assertOk();

        $this->assertNull(collect($this->props()['views'])->firstWhere('id', $id)['config']['endField']);
    }

    #[Test]
    public function tables_can_start_with_a_timeline(): void
    {
        $this->assertSame(['endField' => 'created_at', 'timelineScale' => 'month', 'dateField' => 'close_date'], [
            ...View::timeline('Roadmap')->endField('created_at')->scale('month')->dateField('close_date')->config(),
        ]);

        DealsTable::$startViews = [View::grid('All deals'), View::timeline('Roadmap')->endField('created_at')->scale('quarter')];

        $timeline = $this->props()['views'][1];

        $this->assertSame('timeline', $timeline['type']);
        $this->assertSame('close_date', $timeline['config']['dateField']);
        $this->assertSame('created_at', $timeline['config']['endField']);
        $this->assertSame('quarter', $timeline['config']['timelineScale']);
        $this->assertArrayNotHasKey('formUrl', $timeline);
    }
}
