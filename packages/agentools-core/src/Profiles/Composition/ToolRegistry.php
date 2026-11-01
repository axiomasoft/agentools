<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance, ResourceKind};

final readonly class ToolRegistry
{
    public Dependency $dependency;
    /**
     * @var array<string, RegisteredTool>
     */ public array $tools;
    /**
     * @param list<RegisteredTool> $tools
     */
    public function __construct(string $id, string $version, array $tools, Provenance $provenance, CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        if (count($tools) > 256) { throw new \InvalidArgumentException('Bounded code registry required'); }
        $indexed = [];
        foreach ($tools as $tool) {
            if (isset($indexed[$tool->definition->id])) { throw new \InvalidArgumentException('Ambiguous installed tool version'); }
            $indexed[$tool->definition->id] = $tool;
        }
        ksort($indexed, SORT_STRING); $this->tools = $indexed;
        $this->dependency = Document::dependency(OwnerScope::platform(), ResourceKind::ExecutionPolicy, $id, $version, $provenance,
            ['format'=>'profile-tool-registry/v1', 'tools'=>array_map(static fn (RegisteredTool $t): array => $t->toArray(), array_values($indexed))], $json, $expectedHash);
    }
}
