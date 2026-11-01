<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Finalization target; chosen after provider-independent constraints. */
final readonly class ContextTarget
{
    public function __construct(public Ref $deployment, public int $window, public int $outputReserve,
        public int $safetyMargin, public int $requestedMaxOutput, public ?string $modelVersion = null,
        public ?Ref $capabilitySnapshot = null, public ?PayloadHash $capabilityHash = null)
    {
        if ($capabilitySnapshot !== null) { $deployment->tenant->assertSame($capabilitySnapshot->tenant); }
        if (min($window, $outputReserve, $safetyMargin, $requestedMaxOutput) < 0
            || max($window, $outputReserve, $safetyMargin, $requestedMaxOutput) > 9007199254740991
            || $outputReserve < $requestedMaxOutput || $window < $outputReserve + $safetyMargin || $modelVersion === ''
            || (($capabilitySnapshot === null) !== ($capabilityHash === null))) {
            throw new \InvalidArgumentException('Explicit nonnegative input/output budgets required');
        }
    }
    public function inputBudget(): int { return $this->window - $this->outputReserve - $this->safetyMargin; }
}
