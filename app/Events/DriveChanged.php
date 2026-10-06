<?php

namespace App\Events;

use App\Enums\DriveSpaceKind;
use App\Sandbox\Drive\DriveSpace;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something in one of Drive's places changed, from the web, a computer or a project (DRIVE-001), so open Drive pages
 * on it reload. A person's My Drive is told on their own channel; shared places on the organization's. Names nothing
 * but the place: the page fetches what it may see.
 */
class DriveChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $organizationId, public string $space, public ?int $userId = null) {}

    /**
     * Tell open pages the place changed: in a web request once, after the response is sent; elsewhere straight away.
     */
    public static function signal(DriveSpace $space): void
    {
        $event = new self($space->organizationId, $space->key(), $space->kind === DriveSpaceKind::Personal ? $space->userId : null);

        if (app()->runningInConsole()) {
            event($event);

            return;
        }

        defer(fn () => event($event), "drive-changed-{$space->organizationId}-{$space->key()}-{$space->userId}", always: true);
    }

    public function broadcastAs(): string
    {
        return 'DriveChanged';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->userId !== null ? "drive.{$this->organizationId}.user.{$this->userId}" : "drive.{$this->organizationId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['space' => $this->space];
    }
}
