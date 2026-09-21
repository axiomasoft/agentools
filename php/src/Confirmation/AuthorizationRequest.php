<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

final readonly class AuthorizationRequest
{
    /**
     * @param array<string, scalar|null> $environmentAttributes
     * @param array<string, scalar|null> $riskAttributes
     */
    public function __construct(
        public AuthenticatedActor $actor,
        public string $tenantId,
        public string $resourceId,
        public string $actionId,
        public string $toolName,
        public string $proposalId,
        public int $proposalVersion,
        public ArgumentsFingerprint $argsFingerprint,
        public DescriptorDigest $descriptorDigest,
        public string $policyId,
        public string $policyRevision,
        public array $environmentAttributes = [],
        public array $riskAttributes = [],
        public ?string $delegationId = null,
    ) {
        if (in_array('', [$tenantId, $resourceId, $actionId, $toolName, $proposalId, $policyId, $policyRevision], true) || $proposalVersion < 1) {
            throw new \InvalidArgumentException('Authorization request requires complete context.');
        }
        if ($actor->tenantId !== $tenantId || $delegationId === '') {
            throw new \InvalidArgumentException('Authorization actor context does not match.');
        }
    }
}
