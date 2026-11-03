<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

final class InspectRun extends OperationCommand
{
    protected string $verb = 'inspect-run';
    protected $signature = 'agentools:inspect-run {operation_ref : Trusted host operation handle} {--apply : Explicit mutation; default is dry-run} {--limit=50 : Bounded metadata page, 1..100} {--after= : Stable metadata cursor}';
    protected $description = 'Authorized AgenTools inspect-run; metadata only, stable identities';
}
