<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

final class Prune extends OperationCommand
{
    protected string $verb = 'prune';
    protected $signature = 'agentools:prune {operation_ref : Trusted host operation handle} {--apply : Explicit mutation; default is dry-run} {--limit=50 : Bounded metadata page, 1..100} {--after= : Stable metadata cursor}';
    protected $description = 'Authorized AgenTools prune; metadata only, stable identities';
}
