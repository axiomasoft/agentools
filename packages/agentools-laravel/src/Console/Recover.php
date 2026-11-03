<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

final class Recover extends OperationCommand
{
    protected string $verb = 'recover';
    protected $signature = 'agentools:recover {operation_ref : Trusted host operation handle} {--apply : Explicit mutation; default is dry-run} {--limit=50 : Bounded metadata page, 1..100} {--after= : Stable metadata cursor}';
    protected $description = 'Authorized AgenTools recover; metadata only, stable identities';
}
