<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, TenantId};
use AgenToolsPlatform\Core\Profiles\{Dependency, ResourceKind};

final readonly class CompilationInput
{
    /**
     * @var list<PolicyLayer>
     */ public array $layers;
    /**
     * @var array<string, Dependency>
     */ public array $deployments;
    /**
     * @param list<PolicyLayer> $layers
     * @param list<Dependency> $deployments
     */
    public function __construct(public TenantId $tenant, array $layers, public ToolRegistry $registry, array $deployments)
    {
        if (count($layers) > 64 || count($deployments) > 256) { throw new \InvalidArgumentException('Bounded compilation input required'); }
        usort($layers, static fn (PolicyLayer $a, PolicyLayer $b): int => ($a->role->value <=> $b->role->value) ?: strcmp($a->dependency->ref->key(), $b->dependency->ref->key()));
        $this->layers = $layers; $indexed = [];
        foreach ($deployments as $deployment) {
            $id = $deployment->ref->version->id;
            if ($deployment->ref->kind !== ResourceKind::Deployment || isset($indexed[$id])) { throw new \InvalidArgumentException('Unambiguous pinned deployment versions required'); }
            $indexed[$id] = $deployment;
        }
        ksort($indexed, SORT_STRING); $this->deployments = $indexed;
    }
    /**
     * @return list<Dependency> Complete typed dependency closure used by this compiler.
     */
    public function dependencies(): array
    {
        $entries = [$this->registry->dependency, ...array_values($this->deployments)];
        foreach ($this->layers as $layer) {
            $entries[] = $layer->dependency;
            $entries = array_merge($entries, $layer->materials->add, $layer->materials->exclude);
            foreach ($layer->methodology->packs as $pack) { $entries[] = $pack->dependency; }
            if ($layer->methodology->composition !== null) { $entries[] = $layer->methodology->composition->dependency; }
        }
        $result = [];
        foreach ($entries as $entry) {
            $key = $entry->ref->key();
            if (isset($result[$key]) && $entry->toArray() !== $result[$key]->toArray()) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Dependency identity/content conflict'); }
            $result[$key] = $entry;
        }
        ksort($result, SORT_STRING); return array_values($result);
    }
}
