<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Projection of dated wire status; never inferred from a Run status. */
final readonly class ActionReceipt
{
    public function __construct(public ActionId $actionId, public string $wireStatus, public int $version)
    {
        if (!in_array($wireStatus, ['created', 'running', 'awaiting_confirmation', 'applying',
            'reconciliation_required', 'completed', 'rejected', 'expired', 'failed', 'cancelled'], true)
            || $version < 1 || $version > 9007199254740991) {
            throw new \InvalidArgumentException('Expected a dated ActionStatus and positive version');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['action_id' => $this->actionId->toArray(), 'wire_status' => $this->wireStatus, 'version' => $this->version];
    }
}
