<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class AuthenticatedActor
{
    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public string $tenantId,
        public array $roles = [],
        public ?string $delegationId = null,
    ) {
        if ($id === '' || $tenantId === '') {
            throw new \InvalidArgumentException('Authenticated actor identity and tenant are required.');
        }
        if ($delegationId === '') {
            throw new \InvalidArgumentException('Delegation id cannot be empty.');
        }
        if (count($roles) !== count(array_unique($roles)) || in_array('', $roles, true)) {
            throw new \InvalidArgumentException('Actor roles must be unique non-empty strings.');
        }
    }
}
