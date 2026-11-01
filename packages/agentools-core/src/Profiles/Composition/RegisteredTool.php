<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** A code registry projection, never an executable class/URL/SQL loaded from profile data. */
final readonly class RegisteredTool
{
    public string $schemaJson;
    public function __construct(public DefinitionRef $definition, string $schemaJson, CanonicalJson $json)
    {
        if (!json_decode($schemaJson, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) { throw new \InvalidArgumentException('Tool schema object required'); }
        $this->schemaJson = $json->canonicalizeJson($schemaJson);
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['definition'=>$this->definition->toArray(), 'input_schema'=>json_decode($this->schemaJson, false, 64, JSON_THROW_ON_ERROR)]; }
}
