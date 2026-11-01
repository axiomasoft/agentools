<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Profiles\Dependency;

/** Safe field-level evidence: values are hashed rather than returned as diagnostics. */
final readonly class ProfileConflict implements \JsonSerializable
{
    /**
     * @var list<array<string, mixed>>
     */ public array $sourceRefs;
    /**
     * @var list<string>
     */ public array $sourceValuesHashes;

    /**
     * @param list<array{dependency: Dependency, value: mixed}> $sources
     */
    public function __construct(public string $code, public string $field, array $sources, public string $reason, CanonicalJson $json)
    {
        $indexed = [];
        foreach ($sources as $source) {
            $ref = $source['dependency']->ref;
            $hash = 'sha-256:' . hash('sha256', $json->canonicalize($source['value']));
            $indexed[$ref->key() . ':' . $hash] = ['ref'=>$ref->toArray(), 'hash'=>$hash];
        }
        ksort($indexed, SORT_STRING);
        $this->sourceRefs = array_values(array_map(static fn (array $entry): array => $entry['ref'], $indexed));
        $this->sourceValuesHashes = array_values(array_map(static fn (array $entry): string => $entry['hash'], $indexed));
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['code'=>$this->code, 'field'=>$this->field, 'source_refs'=>$this->sourceRefs, 'source_values_hashes'=>$this->sourceValuesHashes, 'reason'=>$this->reason]; }
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return $this->toArray(); }
}
