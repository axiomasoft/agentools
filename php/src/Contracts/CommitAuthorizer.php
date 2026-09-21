<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

/** @deprecated Legacy low-context port. Use ConfirmationAuthorizer. */
interface CommitAuthorizer
{
    public function isAllowed(string $principal, string $toolName): bool;
}
