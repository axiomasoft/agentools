<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

enum RunStatus: string
{
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Running = 'running';
    case WaitingAction = 'waiting_action';
    case WaitingConfirmation = 'waiting_confirmation';
    case WaitingExternal = 'waiting_external';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function allows(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Accepted => [self::Preparing, self::Cancelling],
            self::Preparing => [self::Running, self::Failed, self::Cancelling],
            self::Running => [self::WaitingAction, self::WaitingConfirmation, self::WaitingExternal,
                self::Completed, self::Failed, self::Cancelling],
            self::WaitingAction, self::WaitingConfirmation, self::WaitingExternal => [self::Running, self::Failed, self::Cancelling],
            self::Cancelling => [self::Cancelled],
            self::Completed, self::Failed, self::Cancelled => [],
        }, true);
    }
}
