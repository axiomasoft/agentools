<?php

declare(strict_types=1);

namespace AgenTools\Action;

enum ActionStatus: string
{
    case Created = 'created';
    case Running = 'running';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Applying = 'applying';
    case ReconciliationRequired = 'reconciliation_required';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Expired, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
