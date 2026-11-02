<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

final readonly class Qualification
{
    public function __construct(public Ref $ref, public PayloadHash $tupleHash, public string $decision,
        public UtcInstant $issuedAt, public ?UtcInstant $expiresAt, public Ref $evidence)
    {
        $ref->tenant->assertSame($evidence->tenant);
        if (!in_array($decision, ['qualified', 'rejected', 'insufficient_evidence'], true)
            || ($expiresAt !== null && Time::compare($issuedAt, $expiresAt) >= 0)) {
            throw new \InvalidArgumentException('Invalid qualification');
        }
    }
    public function permits(PayloadHash $hash, UtcInstant $now): bool
    {
        return $this->decision === 'qualified' && $this->tupleHash->value === $hash->value
            && Time::compare($this->issuedAt, $now) <= 0
            && ($this->expiresAt === null || Time::compare($now, $this->expiresAt) < 0);
    }
}
