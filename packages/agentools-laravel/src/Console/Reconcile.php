<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

final class Reconcile extends OperationCommand
{
    protected string $verb = 'reconcile';
    protected $signature = 'agentools:reconcile {operation_ref : Trusted host operation handle} {--apply : Explicit mutation; default is dry-run} {--limit=50 : Bounded metadata page, 1..100} {--after= : Stable metadata cursor}';
    protected $description = 'Authorized AgenTools reconcile; metadata only, stable identities';
}
