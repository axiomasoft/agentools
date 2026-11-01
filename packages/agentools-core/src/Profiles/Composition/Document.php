<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance, ResourceKind, ResourceRef};

/** Hashes typed document content with explicit identity and provenance. */
final class Document
{
    /**
     * @param array<string, mixed> $content
     */
    public static function dependency(OwnerScope $owner, ResourceKind $kind, string $id, string $version,
        Provenance $provenance, array $content, CanonicalJson $json, ?PayloadHash $expected = null): Dependency
    {
        $hash = new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $json->canonicalize([
            'owner'=>$owner->toArray(), 'kind'=>$kind->value, 'id'=>$id, 'version'=>$version,
            'provenance'=>$provenance->toArray(), 'content'=>$content])));
        $expected?->assertSame($hash);
        return new \AgenToolsPlatform\Core\Profiles\Dependency(new \AgenToolsPlatform\Core\Profiles\ResourceRef($owner, $kind, $id, $version, $hash), $provenance);
    }
}
