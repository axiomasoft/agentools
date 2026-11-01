<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};

/** Separate authorized encrypted storage receipt, never part of the prompt manifest. */
final readonly class DiagnosticRef
{
    public function __construct(public Ref $ref, public PayloadHash $snapshotHash, public UtcInstant $expiresAt,
        public Ref $accessDecision, public Ref $auditRef, public bool $encrypted)
    {
        $ref->tenant->assertSame($accessDecision->tenant); $ref->tenant->assertSame($auditRef->tenant);
        if (!$encrypted) { throw new \InvalidArgumentException('Diagnostic prompt storage must be encrypted'); }
    }
}
