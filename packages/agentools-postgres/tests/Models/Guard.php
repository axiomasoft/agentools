<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\CancellationSignal;
use AgenToolsPlatform\Core\Runtime\{InvocationGuard, InvocationPlan};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final class Guard implements InvocationGuard, CancellationSignal
{
    public bool $emergency = false;
    public bool $cancelled = false;
    public ?UtcInstant $instant = null;
    public function __construct(private TestRig $rig) {}
    public function now(): UtcInstant { return $this->instant ?? new UtcInstant(gmdate('Y-m-d\TH:i:s\Z')); }
    public function assertCurrent(ExecutionContext $context, InvocationPlan $plan): void
    {
        $this->rig->boundary('guard');
        $context->assertCurrent($this->rig->fixture->host);
        if ($this->emergency) { throw new ContractViolation(ErrorCode::DeploymentUnavailable); }
    }
    public function isCancellationRequested(): bool { return $this->cancelled; }
}
