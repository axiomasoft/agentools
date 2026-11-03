<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Budget;

use AgenToolsPlatform\Core\Budget\Admission;
use AgenToolsPlatform\Core\Common\Ref;

/** Proof from the actual owner adapter. `admitted` alone never proves settlement/release. */
final readonly class OwnerResolution
{
    public function __construct(public Ref $operation, public string $payloadHash, public string $state,
        public ?Ref $proof = null, public ?Admission $renewedAdmission = null)
    {
        if (!in_array($state,['applied','not_applied','unknown'],true) || ($state !== 'unknown' && $proof === null)) {
            throw new \InvalidArgumentException('Authoritative operation proof required');
        }
        if ($proof !== null) { $operation->tenant->assertSame($proof->tenant); }
    }
}
