<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\PayloadHash;

final readonly class ResourceRef
{
    public DefinitionRef $version;

    public function __construct(public OwnerScope $owner, public ResourceKind $kind, string $id, string $version, PayloadHash $hash)
    {
        $this->version = new \AgenToolsPlatform\Core\Profiles\DefinitionRef($id, $version, $hash);
    }

    /** Identity deliberately excludes content hash: different bytes at the same identity conflict. */
    public function key(): string
    {
        return json_encode([$this->owner->toArray(), $this->kind->value, $this->version->id, $this->version->version], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['owner' => $this->owner->toArray(), 'kind' => $this->kind->value] + $this->version->toArray();
    }
}
