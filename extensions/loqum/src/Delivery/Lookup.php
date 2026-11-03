<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

final readonly class Lookup
{
    /** not_found requires an owner fence proving the old request cannot arrive later. */
    public function __construct(public string $status, public ?PlayoutReceipt $receipt=null)
    {
        if (!in_array($status,['found','not_found','unknown'],true) || ($status==='found')!==($receipt!==null)) {
            throw new \InvalidArgumentException('Explicit lookup certainty required');
        }
    }
}
