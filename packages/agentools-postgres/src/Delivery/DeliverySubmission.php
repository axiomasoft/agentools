<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Delivery;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, RunId};

/** Immutable owner refs. Payload bytes are managed by PayloadRepository and its encrypted store. */
final readonly class DeliverySubmission
{
    public function __construct(public string $key, public RunId $run, public Ref $response,
        public int $responseVersion, public Ref $payload, public PayloadHash $hash, public Ref $destination,
        public Ref $source, public int $sourceRevision, public string $policyRevision, public PayloadHash $displayHash)
    {
        if ($key === '' || strlen($key)>128 || $responseVersion<1 || $sourceRevision<1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $policyRevision)!==1) {
            throw new \InvalidArgumentException('Bounded delivery identity and policy required');
        }
        foreach ([$response,$payload,$destination,$source] as $ref) {
            $run->tenant->assertSame($ref->tenant);
        }
    }
}
