<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash, Ref, TenantId};

/** Strict durable manifest boundary; reconstructing also verifies the complete content digest. */
final readonly class ManifestCodec
{
    public function __construct(private CanonicalJson $json) {}

    public function encode(BundleManifest $manifest): string { return $this->json->canonicalize($manifest->toArray()); }

    public function decode(string $encoded, TenantId $tenant): BundleManifest
    {
        $canonical = $this->json->canonicalizeJson($encoded);
        $d = self::object(json_decode($canonical, true, 64, JSON_THROW_ON_ERROR));
        if (($d['format'] ?? null) !== 'profile-bundle/v1'
            || self::owner(self::object($d['owner'] ?? null))->toArray() !== OwnerScope::tenant($tenant)->toArray()
            || !is_int($d['version'] ?? null) || !is_array($d['dependencies'] ?? null) || !array_is_list($d['dependencies'])) {
            throw new \InvalidArgumentException('Invalid manifest envelope');
        }
        $deps = [];
        foreach ($d['dependencies'] as $entry) {
            $entry = self::object($entry);
            $deps[] = new \AgenToolsPlatform\Core\Profiles\Dependency(self::resource(self::object($entry['resource'] ?? null)), self::provenance(self::object($entry['provenance'] ?? null)));
        }
        // Preserve {} versus [] by decoding compiler payloads from the object form.
        $wire = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        if (!$wire instanceof \stdClass || !($wire->compiled ?? null) instanceof \stdClass) {
            throw new \InvalidArgumentException('Invalid compiled fields');
        }
        $c = self::object($d['compiler'] ?? null);
        $manifest = new \AgenToolsPlatform\Core\Profiles\BundleManifest(new \AgenToolsPlatform\Core\Common\Ref($tenant, self::text($d, 'id')), $d['version'],
            new \AgenToolsPlatform\Core\Profiles\DefinitionRef(self::text($c, 'id'), self::text($c, 'version'), new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($c, 'hash'))),
            self::resource(self::object($d['definition'] ?? null)), self::resource(self::object($d['profile'] ?? null)), $deps,
            new \AgenToolsPlatform\Core\Profiles\CompiledProfile(json_encode($wire->compiled->effective_fields ?? null, JSON_THROW_ON_ERROR),
                json_encode($wire->compiled->resolved_restrictions ?? null, JSON_THROW_ON_ERROR), $this->json),
            self::provenance(self::object($d['provenance'] ?? null)), $this->json, new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($d, 'content_hash')));
        if ($this->encode($manifest) !== $canonical) { throw new \InvalidArgumentException('Unexpected or noncanonical manifest fields'); }
        return $manifest;
    }

    /** @param array<string, mixed> $d */
    public static function resource(array $d): ResourceRef
    {
        return new \AgenToolsPlatform\Core\Profiles\ResourceRef(self::owner(self::object($d['owner'] ?? null)), ResourceKind::from(self::text($d, 'kind')),
            self::text($d, 'id'), self::text($d, 'version'), new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($d, 'hash')));
    }

    /** @param array<string, mixed> $d */
    private static function owner(array $d): OwnerScope
    {
        return match (true) {
            $d === ['kind' => 'platform'] => OwnerScope::platform(),
            count($d) === 2 && ($d['kind'] ?? null) === 'tenant' => OwnerScope::tenant(new \AgenToolsPlatform\Core\Common\TenantId(self::text($d, 'tenant_id'))),
            default => throw new \InvalidArgumentException('Explicit tagged owner required'),
        };
    }

    /** @param array<string, mixed> $d */
    private static function provenance(array $d): Provenance
    {
        return new \AgenToolsPlatform\Core\Profiles\Provenance(self::text($d, 'created_by_ref'), self::text($d, 'validation_ref'), self::text($d, 'evaluation_ref'));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $d): array
    {
        if (!is_array($d) || array_is_list($d)) { throw new \InvalidArgumentException('Object required'); }
        foreach ($d as $key => $_) { if (!is_string($key)) { throw new \InvalidArgumentException('Object keys required'); } }
        return $d;
    }

    /** @param array<string, mixed> $d */
    private static function text(array $d, string $key): string
    {
        if (!is_string($d[$key] ?? null)) { throw new \InvalidArgumentException('Manifest string missing'); }
        return $d[$key];
    }
}
