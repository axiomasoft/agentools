<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\CapabilityEvidence;

/** Per-operation evidence. Missing/unknown/unverified/expired is unsupported at the boundary. */
final readonly class Capabilities
{
    /** @param array<string,CapabilityEvidence> $operations */
    public function __construct(private array $operations=[])
    {
        if (array_diff(array_keys($operations),['create','use','refresh','delete','status'])!==[]) {
            throw new \InvalidArgumentException('Unknown cache operation');
        }
    }
    public function allows(string $operation,UtcInstant $now): bool
    { return isset($this->operations[$operation]) && $this->operations[$operation]->permits($now); }
}
