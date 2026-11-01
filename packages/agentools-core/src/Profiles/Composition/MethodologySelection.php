<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

final readonly class MethodologySelection
{
    /**
     * @param list<MethodologyPack> $packs
     */
    public function __construct(public array $packs = [], public ?MethodologyComposition $composition = null)
    {
        if (count($packs) > 16) { throw new \InvalidArgumentException('Methodology selection is bounded'); }
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['packs'=>array_map(static fn (MethodologyPack $p): array => $p->toArray(), $this->packs),
            'composition'=>$this->composition?->dependency->toArray()];
    }
}
