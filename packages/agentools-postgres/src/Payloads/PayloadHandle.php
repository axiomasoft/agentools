<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\Ref;

/** Trusted resolver input, never a log/audit payload or model-provided secret reference. */
final readonly class PayloadHandle
{
    public function __construct(public Ref $slot, public Ref $encryptedRef, public Ref $encryptionKeyRef)
    {
        $slot->tenant->assertSame($encryptedRef->tenant);
        $slot->tenant->assertSame($encryptionKeyRef->tenant);
    }
}
