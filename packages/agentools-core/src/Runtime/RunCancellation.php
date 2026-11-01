<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Actions\Cancellation\Operation;
use AgenToolsPlatform\Core\Common\{ErrorCode, RunStatus};

/** Cancellation policy only. Never changes an Action, delivery or owner outcome. */
final class RunCancellation
{
    public function next(RunStatus $status, Operation $command, bool $unresolved): RunStatus
    {
        if (!in_array($command->kind,['run_cancel','run_finalize'],true)) { throw new \InvalidArgumentException('Run cancellation command required'); }
        if ($status->terminal()) { return $status; }
        if ($command->kind==='run_cancel') { return RunStatus::Cancelling; }
        if ($status!==RunStatus::Cancelling || $command->evidence===null || $unresolved) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::ManualResolutionRequired);
        }
        return RunStatus::Cancelled;
    }
}
