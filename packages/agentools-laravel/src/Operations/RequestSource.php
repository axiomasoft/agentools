<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

/** Host resolves target, key, reason and current authenticated context from a trusted handle. */
interface RequestSource
{
    public function command(string $handle,string $verb,bool $apply,int $limit,?string $after): Command;
}
