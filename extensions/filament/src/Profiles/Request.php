<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

/** Untrusted form data. Tenant/session/actor and service classes are deliberately absent. */
final readonly class Request
{
    public function __construct(public Operation $operation, public string $id = '', public int $version = 1,
        public string $hash = '', public int $expected = 0, public string $manifest = '',
        public string $instance = '', public string $command = '', public string $reason = '') {}
}
