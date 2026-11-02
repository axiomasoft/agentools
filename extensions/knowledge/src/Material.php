<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Technical Library/Content projection; Files/Vaulter retain immutable source bytes. */
final readonly class Material
{
    /**
     * @param non-empty-list<string> $audiences
     * @param list<string> $scopes
     */
    public function __construct(public string $tenant, public string $id, public string $version,
        public string $ownerRef, public string $accessPolicyRef, public string $licenseRef,
        public string $bytesRef, public string $sourceHash, public array $audiences,
        public array $scopes, public string $locale)
    {
        foreach ([$tenant, $id, $version, $ownerRef, $accessPolicyRef, $licenseRef, $bytesRef, $locale] as $value) {
            Integrity::text($value);
        }
        Integrity::hash($sourceHash); Integrity::names($audiences); Integrity::names($scopes);
        if ($audiences === []) { throw new \InvalidArgumentException('Audience required'); }
    }

    public function key(): string { return Integrity::digest([$this->tenant, $this->id, $this->version]); }
}
