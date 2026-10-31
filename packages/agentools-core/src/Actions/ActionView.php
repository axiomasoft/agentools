<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{ActionReceipt, ActionPhaseMapping, Ref};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Safe projection only. Unknown outcome retains reconciliation_required, never failed/success. */
final readonly class ActionView
{
    public function __construct(
        public ActionReceipt $receipt,
        public DefinitionRef $definition,
        public string $safeNextStep,
        public ?Ref $resultRef = null,
    ) {
        if (!in_array($safeNextStep, ['status', 'confirm', 'reconcile', 'manual_resolution', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid safe next step');
        }
        if ($resultRef !== null) {
            $receipt->actionId->tenant->assertSame($resultRef->tenant);
        }
    }

    public function projectPhase(string $phase, bool $dispatchEligible = false): string
    {
        return ActionPhaseMapping::project($phase, $this->receipt->wireStatus, $dispatchEligible);
    }
}
