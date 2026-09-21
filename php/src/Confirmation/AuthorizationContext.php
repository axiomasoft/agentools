<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class AuthorizationContext
{
    /**
     * @param array<string, scalar|null> $environmentAttributes
     * @param array<string, scalar|null> $riskAttributes
     */
    public function __construct(
        public string $tenantId,
        public string $resourceId,
        public array $environmentAttributes = [],
        public array $riskAttributes = [],
        public ?string $delegationId = null,
    ) {
        if ($tenantId === '' || $resourceId === '') {
            throw new \InvalidArgumentException('Authorization tenant and resource are required.');
        }
        if ($delegationId === '') {
            throw new \InvalidArgumentException('Delegation id cannot be empty.');
        }
    }
}
