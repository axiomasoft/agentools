<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Immutable technical request binding. The external owner retains payload/authorization ownership. */
final readonly class EffectIntent
{
    public function __construct(public string $effectKey, public string $ownerNamespace,
        public string $ownerOperationKey, public PayloadHash $requestHash, public Ref $requestRef)
    {
        foreach ([$effectKey, $ownerNamespace, $ownerOperationKey] as $value) {
            if ($value === '' || strlen($value) > 512) {
                throw new \InvalidArgumentException('Bounded immutable effect identity required');
            }
        }
    }
}
