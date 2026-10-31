<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** ALC projection only: the dated journal remains the transition authority. */
final class ActionPhaseMapping
{
    public static function project(string $phase, string $journaledWireStatus, bool $dispatchEligible = false): string
    {
        $wire = match ($phase) {
            'pending' => 'created', 'ready' => 'running', 'executing' => 'applying',
            'succeeded' => 'completed', 'reconciling' => 'reconciliation_required',
            'awaiting_confirmation' => 'awaiting_confirmation', 'rejected' => 'rejected',
            'expired' => 'expired', 'failed' => 'failed', 'cancelled' => 'cancelled',
            default => throw new \InvalidArgumentException('Unknown Action phase; Run/operational reasons are not wire statuses'),
        };
        if ($wire !== $journaledWireStatus || ($phase === 'ready' && !$dispatchEligible)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict);
        }
        return $wire;
    }
}
