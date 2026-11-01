<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Profiles\{Dependency, ResourceKind};

final readonly class MaterialChanges
{
    /**
     * @param list<Dependency> $add
     * @param list<Dependency> $exclude
     */
    public function __construct(public array $add = [], public array $exclude = [])
    {
        $seen = [];
        foreach ([$add, $exclude] as $entries) {
            foreach ($entries as $entry) {
                if ($entry->ref->kind !== ResourceKind::Knowledge || isset($seen[$entry->ref->key()])) { throw new \InvalidArgumentException('Unambiguous explicit material additions/exclusions required'); }
                $seen[$entry->ref->key()] = true;
            }
        }
    }
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function toArray(): array
    {
        $encode = static fn (Dependency $d): array => $d->toArray();
        return ['add'=>array_map($encode, $this->add), 'exclude'=>array_map($encode, $this->exclude)];
    }
}
