<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Contracts\CommitAuthorizer;

/** Commit-time permission check using the bound authorization permissions list. */
final class PermissionCommitAuthorizer implements CommitAuthorizer
{
    /** @param list<string> $permissions */
    public function __construct(private readonly array $permissions)
    {
    }

    public function isAllowed(string $principal, string $toolName): bool
    {
        return $this->permissions !== [];
    }
}
