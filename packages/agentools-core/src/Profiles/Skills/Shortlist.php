<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\PayloadHash;

final readonly class Shortlist implements \JsonSerializable
{
    /**
     * @param list<PublishedSkill> $entries
     */
    public function __construct(public array $entries, public PayloadHash $routingHash, int $limit)
    {
        if (count($entries) > $limit || $limit > 16 || $limit < 1) { throw new \InvalidArgumentException('Shortlist must be bounded'); }
    }
    /**
     * Only the bounded metadata, never a registry, fragment body or input history.
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['routing_hash'=>$this->routingHash->value,'manifests'=>array_map(static fn (PublishedSkill $s): array => $s->view(),$this->entries)];
    }
}
