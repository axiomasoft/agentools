<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref,UtcInstant};

/** Only an opaque locator and receipt; no key ref, ciphertext or bearer grant. */
final readonly class DiagnosticReceipt
{
    public function __construct(public Ref $id,public Ref $target,public PayloadHash $contentHash,
        public UtcInstant $expiresAt,public Ref $audit)
    { $id->tenant->assertSame($target->tenant);$id->tenant->assertSame($audit->tenant); }
}
