<?php

namespace Tests\Feature\Tables;

use App\Events\TableChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('TABLE-001')]
class BroadcastTest extends TablesTestCase
{
    #[Test]
    public function record_changes_are_sent_to_others_and_linking_tables_reload(): void
    {
        Event::fake([TableChanged::class]);
        $kept = $this->deal();
        $gone = $this->deal();

        $created = $this->changes('deals', [
            'creates' => [['values' => ['name' => 'New']]],
            'updates' => [['id' => $kept->id, 'values' => ['name' => 'Changed']]],
            'deletes' => [$gone->id],
        ])->json('records.0.id');

        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table === 'deals'
            && $event->records === [$created, $kept->id]
            && $event->deleted === [$gone->id]
            && ! $event->reload);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table === 'companies' && $event->reload);
        Event::assertNotDispatched(TableChanged::class, fn (TableChanged $event) => $event->table === 'notes');
    }

    #[Test]
    public function field_and_view_changes_tell_others_to_reload(): void
    {
        Event::fake([TableChanged::class]);

        $this->addField('deals', ['name' => 'Score', 'type' => 'number']);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table === 'deals' && $event->reload);

        Event::fake([TableChanged::class]);
        $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Shared', 'type' => 'grid'])->assertCreated();
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table === 'deals' && $event->reload);
    }

    #[Test]
    public function the_event_goes_on_the_tables_private_channel(): void
    {
        $event = new TableChanged('deals', records: [1], reload: false);

        $this->assertEquals([new PrivateChannel('tables.deals')], $event->broadcastOn());
        $this->assertSame('table.changed', $event->broadcastAs());
        $this->assertSame(['records' => [1]], $event->broadcastWith());
    }

    #[Test]
    public function a_failing_broadcaster_never_fails_a_save(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
            'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        $deal = $this->deal();

        $this->updateRecord('deals', $deal->id, ['name' => 'Still saved']);

        $this->assertSame('Still saved', $deal->fresh()->name);
    }
}
