<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;

/** Immutable published bundle pin. It grants no current permission. */
final readonly class BundleRef
{
    public function __construct(public Ref $ref, public int $version, public PayloadHash $hash)
    {
        if ($version < 1 || $version > 9007199254740991) {
            throw new \InvalidArgumentException('Bundle version must be positive');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ref' => $this->ref->toArray(), 'version' => $this->version, 'hash' => $this->hash->value];
    }
}
