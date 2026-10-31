<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\UtcInstant;

final readonly class CapabilityEvidence
{
    public function __construct(public Support $support, public Evidence $evidence,
        public UtcInstant $checkedAt, public UtcInstant $expiresAt, public string $evidenceRef)
    {
        if (Time::compare($checkedAt, $expiresAt) >= 0 || $evidenceRef === '') {
            throw new \InvalidArgumentException('Capability evidence needs a bounded lifetime and source');
        }
    }

    public function permits(UtcInstant $now): bool
    {
        return $this->support === Support::Supported && $this->evidence !== Evidence::Unverified
            && Time::compare($this->checkedAt, $now) <= 0 && Time::compare($now, $this->expiresAt) < 0;
    }
}
