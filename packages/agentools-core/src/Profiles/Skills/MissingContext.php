<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Profiles\ResourceRef;

final readonly class MissingContext implements \JsonSerializable
{
    /**
     * @param list<string> $kinds
     * @param list<ResourceRef> $skills
     */
    public function __construct(public array $kinds, public array $skills) {}
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return ['code'=>'missing_context','required_kinds'=>$this->kinds,'skills'=>array_map(static fn (ResourceRef $r): array => $r->toArray(),$this->skills)]; }
}
