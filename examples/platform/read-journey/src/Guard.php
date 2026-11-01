<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\CancellationSignal;
use AgenToolsPlatform\Core\Runtime\{InvocationGuard, InvocationPlan};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final class Guard implements InvocationGuard, CancellationSignal
{
    public bool $cancelled = false;
    public function __construct(private SupportJourney $journey) {}
    public function now(): UtcInstant { return new UtcInstant((new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')); }
    public function assertCurrent(ExecutionContext $context, InvocationPlan $plan): void
    {
        $j=$this->journey; $j->idle(); $context->assertCurrent($j->host);
        $row=$j->db->row('SELECT emergency FROM ' . $j->db->table('demo_permissions') . ' WHERE tenant_id=:tenant', ['tenant'=>$context->session->tenant->value]);
        if ($row===null || $row['emergency']!==false) { throw new ContractViolation(ErrorCode::DeploymentUnavailable); }
    }
    public function isCancellationRequested(): bool { return $this->cancelled; }
}
