<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref};

final readonly class AdmissionRequest
{
    public PayloadHash $hash;
    /** @param array<string, mixed> $content Stable refs, estimate and policy/scope hashes; no raw prompt. */
    public function __construct(public Ref $operationKey, public array $content, CanonicalHasher $hasher)
    {
        $this->hash = $hasher->hash($operationKey->tenant, 'invocation-admission', $content);
    }
}
