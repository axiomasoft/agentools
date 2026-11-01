<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

enum Strategy: string
{
    case NativeKey = 'native_key';
    case ReadBeforeWrite = 'read_before_write';
    case Unsupported = 'unsupported';

    /** Same takeover invariants as the dated IdempotencyCoordinator. No probe is not absence. */
    public function takeoverDecision(LookupStatus $status): bool
    {
        if (in_array($status, [LookupStatus::Succeeded, LookupStatus::Failed], true)) {
            return false;
        }
        return $this === self::NativeKey
            || ($this === self::ReadBeforeWrite && $status === LookupStatus::Absent);
    }
}
