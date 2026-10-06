<?php

namespace App\Sandbox\Drive;

use App\Enums\DriveSpaceKind;

/**
 * One of Drive's places (DRIVE-001): a person's My Drive, an organization's shared drive, or a group's drive. Named by
 * a key in addresses and the sync API: `personal`, `organization` or `group-<id>` (My Drive is always the viewer's own).
 */
final readonly class DriveSpace
{
    public function __construct(
        public DriveSpaceKind $kind,
        public int $organizationId,
        public ?int $userId = null,
        public ?int $groupId = null,
        public string $name = '',
    ) {}

    /**
     * Its key in addresses and the sync API.
     */
    public function key(): string
    {
        return match ($this->kind) {
            DriveSpaceKind::Personal => 'personal',
            DriveSpaceKind::Organization => 'organization',
            DriveSpaceKind::Group => "group-{$this->groupId}",
        };
    }

    /**
     * Whether it's the same place as another.
     */
    public function is(DriveSpace $other): bool
    {
        return $this->kind === $other->kind && $this->organizationId === $other->organizationId
            && $this->userId === $other->userId && $this->groupId === $other->groupId;
    }

    /**
     * The same place under another name.
     */
    public function named(string $name): self
    {
        return new self($this->kind, $this->organizationId, $this->userId, $this->groupId, $name);
    }
}
