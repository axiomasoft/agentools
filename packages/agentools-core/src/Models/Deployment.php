<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;

final readonly class Deployment
{
    /** @param array<string, CapabilityEvidence> $capabilities */
    public function __construct(public Ref $ref, public string $provider, public string $account,
        public string $region, public string $model, public array $capabilities)
    {
        foreach ([$provider, $account, $region, $model] as $value) {
            if ($value === '') { throw new \InvalidArgumentException('Deployment identity must be complete'); }
        }
        foreach ($capabilities as $key => $value) {
            if (Capability::tryFrom($key) === null) { throw new \InvalidArgumentException('Unknown capability'); }
        }
    }

    public function supports(Capability $capability, UtcInstant $now): bool
    {
        return isset($this->capabilities[$capability->value]) && $this->capabilities[$capability->value]->permits($now);
    }

    public function sameBinding(self $other): bool
    {
        return $this->ref->tenant->value === $other->ref->tenant->value
            && $this->provider === $other->provider && $this->account === $other->account
            && $this->region === $other->region;
    }
}
