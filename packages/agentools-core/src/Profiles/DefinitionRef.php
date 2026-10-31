<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\PayloadHash;

/** Code registry ID/version/hash; never a database-loaded class, URL or SQL. */
final readonly class DefinitionRef
{
    public function __construct(public string $id, public string $version, public PayloadHash $hash)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $id) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $version) !== 1) {
            throw new \InvalidArgumentException('Expected a code registry ID and explicit version');
        }
    }

    /** @return array{id: string, version: string, hash: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'hash' => $this->hash->value];
    }
}
