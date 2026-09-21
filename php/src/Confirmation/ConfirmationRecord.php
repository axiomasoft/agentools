<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class ConfirmationRecord
{
    public function __construct(
        public string $tenantId,
        public Proposal $proposal,
        public AuthorizationContext $context,
        public ResolvedPolicy $policy,
        public string $tokenDigest,
    ) {
        if ($tenantId === '' || $proposal->proposerId === '' || $tenantId !== $context->tenantId) {
            throw new \InvalidArgumentException('Confirmation record requires a matching tenant and proposer.');
        }
        if (preg_match('/^sha-256:[0-9a-f]{64}$/', $tokenDigest) !== 1) {
            throw new \InvalidArgumentException('Confirmation token digest must be sha-256.');
        }
        if ($proposal->principal !== $policy->commitPrincipal) {
            throw new \InvalidArgumentException('Proposal principal must equal the policy commit principal.');
        }
    }

    public static function digestToken(string $token): string
    {
        return 'sha-256:' . hash('sha256', $token);
    }
}
