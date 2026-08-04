<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

interface CommitAuthorizer
{
    public function isAllowed(string $principal, string $toolName): bool;
}
