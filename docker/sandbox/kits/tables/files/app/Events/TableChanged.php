<?php

namespace App\Events;

use App\Tables\Tables;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Records of a table changed, or its fields or views did (reload). Sent to everyone else viewing the table.
 */
class TableChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $records  created or updated
     * @param  list<int>  $deleted
     */
    public function __construct(
        public string $table,
        public array $records = [],
        public array $deleted = [],
        public bool $reload = false,
    ) {}

    /**
     * Tell the others, without ever failing the request (e.g. when Reverb isn't running).
     *
     * @param  list<int>  $records
     * @param  list<int>  $deleted
     */
    public static function send(string $table, array $records = [], array $deleted = [], bool $reload = false): void
    {
        if ($records === [] && $deleted === [] && ! $reload) {
            return;
        }

        rescue(function () use ($table, $records, $deleted, $reload) {
            $pending = broadcast(new static($table, $records, $deleted, $reload));
            $pending->toOthers();
            unset($pending);
        }, report: false);
    }

    /**
     * Tell the tables that link to this one (and so show its titles, lookups and rollups) to reload.
     */
    public static function reloadLinking(string $table): void
    {
        foreach (Tables::keys() as $key) {
            if ($key === $table) {
                continue;
            }

            foreach (Tables::find($key)?->allFields() ?? [] as $field) {
                if ($field->type === 'link' && $field->option('table') === $table) {
                    static::send($key, reload: true);

                    break;
                }
            }
        }
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tables.{$this->table}")];
    }

    public function broadcastAs(): string
    {
        return 'table.changed';
    }

    /**
     * @return array{records?: list<int>, deleted?: list<int>, reload?: bool}
     */
    public function broadcastWith(): array
    {
        return array_filter([
            'records' => $this->records,
            'deleted' => $this->deleted,
            'reload' => $this->reload,
        ]);
    }
}
