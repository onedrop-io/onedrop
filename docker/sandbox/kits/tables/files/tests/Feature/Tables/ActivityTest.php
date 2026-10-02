<?php

namespace Tests\Feature\Tables;

use App\Models\TableActivity;
use App\Models\TableComment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('TABLE-007')]
class ActivityTest extends TablesTestCase
{
    #[Test]
    public function a_records_comments_and_history_come_together_oldest_first(): void
    {
        $id = $this->changes('deals', ['creates' => [['values' => ['name' => 'Start']]]])->json('records.0.id');
        $this->updateRecord('deals', $id, ['stage' => 'Won']);

        $comment = $this->actingAs($this->user)->postJson("/tables/deals/records/{$id}/comments", ['body' => 'Nice work'])->assertCreated()->json('item');

        $this->assertSame('comment', $comment['kind']);
        $this->assertSame('Nice work', $comment['body']);
        $this->assertSame(['id' => $this->user->id, 'name' => 'Dana Dev', 'avatar' => null], $comment['user']);

        $items = $this->actingAs($this->user)->getJson("/tables/deals/records/{$id}/activity")->assertOk()->json('items');

        $this->assertSame(['created', 'updated', 'comment'], array_column($items, 'kind'));
        $this->assertSame(['field' => 'stage', 'fieldName' => 'Stage', 'from' => 'Lead', 'to' => 'Won'], array_intersect_key($items[1], array_flip(['field', 'fieldName', 'from', 'to'])));
        $this->assertSame($comment, $items[2]);
    }

    #[Test]
    public function history_survives_the_field_being_deleted(): void
    {
        $key = $this->addField('deals', ['name' => 'Score', 'type' => 'number']);
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, [$key => 7]);

        $this->actingAs($this->user)->deleteJson("/tables/deals/fields/{$key}")->assertOk();

        $item = $this->actingAs($this->user)->getJson("/tables/deals/records/{$deal->id}/activity")->json('items.0');
        $this->assertSame(['fieldName' => 'Score', 'from' => null, 'to' => '7'], array_intersect_key($item, array_flip(['fieldName', 'from', 'to'])));
    }

    #[Test]
    public function comments_are_checked_and_only_their_author_deletes_them(): void
    {
        $deal = $this->deal();
        $sam = User::factory()->create();

        $this->actingAs($this->user)->postJson("/tables/deals/records/{$deal->id}/comments", ['body' => str_repeat('a', 10001)])
            ->assertUnprocessable()->assertJsonPath('errors', ['body' => ['Comments can be up to 10,000 characters.']]);

        $commentId = $this->actingAs($this->user)->postJson("/tables/deals/records/{$deal->id}/comments", ['body' => 'Mine'])->json('item.commentId');

        $this->actingAs($sam)->deleteJson("/tables/deals/comments/{$commentId}")->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/tables/companies/comments/{$commentId}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/tables/deals/comments/{$commentId}")->assertNoContent();
        $this->assertSame(0, TableComment::query()->count());
    }

    #[Test]
    public function deleting_a_record_deletes_its_comments_and_history(): void
    {
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, ['name' => 'Changed']);
        $this->actingAs($this->user)->postJson("/tables/deals/records/{$deal->id}/comments", ['body' => 'Hi'])->assertCreated();

        $this->changes('deals', ['deletes' => [$deal->id]])->assertOk();

        $this->assertSame(0, TableComment::query()->count());
        $this->assertSame(0, TableActivity::query()->count());
    }
}
