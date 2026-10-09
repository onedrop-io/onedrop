<?php

namespace App\Enums;

/**
 * Where a move to a new sandbox is (SBX-005): each queue job advances it a step or two.
 */
enum SandboxMovePhase: string
{
    case Starting = 'starting';
    case Snapshotting = 'snapshotting';
    case Creating = 'creating';
    case Restoring = 'restoring';
    case Finishing = 'finishing';
    case Done = 'done';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this !== self::Done && $this !== self::Failed;
    }

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return array_values(array_map(fn (self $phase) => $phase->value, array_filter(self::cases(), fn (self $phase) => $phase->isActive())));
    }
}
