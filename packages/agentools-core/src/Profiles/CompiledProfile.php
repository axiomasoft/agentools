<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\CanonicalJson;

/** Frozen compiler output. Effective fields and resolved restrictions occupy separate objects. */
final readonly class CompiledProfile
{
    public string $effectiveJson;
    public string $restrictionsJson;

    public function __construct(string $effectiveJson, string $restrictionsJson, CanonicalJson $json)
    {
        foreach ([$effectiveJson, $restrictionsJson] as $value) {
            if (!json_decode($value, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                throw new \InvalidArgumentException('Compiler output must contain separate JSON objects');
            }
        }
        $this->effectiveJson = $json->canonicalizeJson($effectiveJson);
        $this->restrictionsJson = $json->canonicalizeJson($restrictionsJson);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['effective_fields' => json_decode($this->effectiveJson, false, 64, JSON_THROW_ON_ERROR),
            'resolved_restrictions' => json_decode($this->restrictionsJson, false, 64, JSON_THROW_ON_ERROR)];
    }
}
