<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Prepared immutable visible export. It is persisted under the existing response identity. */
final readonly class ResponsePublication
{
    public function __construct(public Ref $payload, public PayloadHash $hash, public string $deliveryKey)
    {
        if ($deliveryKey === '' || strlen($deliveryKey) > 128) {
            throw new \InvalidArgumentException('A bounded stable delivery key is required');
        }
    }
}
