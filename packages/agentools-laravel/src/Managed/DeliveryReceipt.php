<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Common\{DeliveryId, Ref};

/** Accepted/generated is independent from delivered. Null acknowledgement remains unknown. */
final readonly class DeliveryReceipt
{
    public function __construct(public DeliveryId $id, public ?Ref $acknowledgement)
    {
        if ($acknowledgement !== null) { $id->tenant->assertSame($acknowledgement->tenant); }
    }
}
