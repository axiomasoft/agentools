<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Immutable dependency pins; host authorization remains current above these pins. */
final readonly class SnapshotPins
{
    /**
     * @param array<array-key, array{ref: Ref, version: string, hash: PayloadHash}> $dependencies skills/policy/domain/material/corpus/release/fragment/cache refs
     * @param array<string, string|int|bool|null> $requestedModelParams
     */
    public function __construct(public array $dependencies = [], public ?Ref $routingDecision = null,
        public array $requestedModelParams = [], public string $retention = 'host-policy')
    {
        if (!array_is_list($dependencies) || $retention === '') { throw new \InvalidArgumentException('Pinned dependencies required'); }
        foreach ($dependencies as $pin) {
            if ($pin['version'] === '') { throw new \InvalidArgumentException('Pinned version required'); }
        }
    }
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['dependencies'=>array_map(static fn(array $p): array=>['ref'=>$p['ref']->toArray(),'version'=>$p['version'],'hash'=>$p['hash']->value],$this->dependencies),
            'routing_decision_ref'=>$this->routingDecision?->toArray(), 'requested_model_params'=>$this->requestedModelParams, 'retention'=>$this->retention];
    }
}
