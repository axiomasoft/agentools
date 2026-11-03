<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\EdufyHost;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, InputPayload, InstanceId, Ref, SessionId, SourceIdentity, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Context\{BlockKind, Classification, ContextBlock, TrustClass};
use AgenToolsPlatform\Core\Security\{BoundaryClock, Correlation, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Laravel\Host\{ContextState, HostOwner, HostRequest};
use AgenToolsPlatform\Laravel\Managed\Input;

/** Synthetic owner APIs, local development only; no external Edufy implementation. */
final class SchoolHost implements HostOwner, BoundaryClock
{
    public bool $allowed = true;
    public bool $emergency = false;
    public bool $sliceAllowed = true;
    public bool $available = true;
    public string $actor = 'teacher-1';
    public string $subject = 'student-1';
    public string $consent = 'consent-1';
    public string $policy = 'policy-1';
    public string $sliceTenant = '';
    public string $slicePurpose = 'education.report';
    public int $revision = 1;
    /** @var list<string> */
    public array $capability = ['report.read', 'report.amend'];
    public ?TenantId $active = null;
    public int $resets = 0;
    /** @var array<string, mixed> */
    public array $fixture;
    public string $instant;
    public string $expiry;

    public function __construct(public readonly CanonicalHasher $hasher)
    {
        $this->fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->instant = $this->fixture['now']; $this->expiry = $this->fixture['expires_at'];
    }
    public function request(Input $handle): HostRequest
    {
        if (!$this->available) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        $row = $this->fixture['requests'][$handle->ref] ?? null;
        if ($row === null) { throw new ContractViolation(ErrorCode::Unauthenticated); }
        $this->active = $tenant = new TenantId($row['tenant']);
        return new HostRequest(new SessionId($tenant, $row['session']),
            new SourceIdentity(new InstanceId($tenant, $row['instance']), $row['source_kind'], $row['event_id']),
            new InputPayload($this->ref('host:input:'.$row['event_id']), $this->hasher->hash($tenant, 'host-content', $row['payload']),
                $this->ref('conversation-1'), $this->ref($row['event_id'])),
            $row['purpose'], new Correlation('correlation-1', 'request-1'));
    }
    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        if (!$this->available) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        if ($this->active === null) { throw new ContractViolation(ErrorCode::Unauthenticated); }
        $this->active->assertSame($session->tenant);
        if (!$this->allowed || $this->emergency || $purpose !== 'education.report') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->active, $this->ref($this->actor), null, $this->ref('authorization-1'),
            $this->policy, $this->ref($this->subject), $this->ref($this->consent));
    }
    public function state(ExecutionContext $context): ContextState
    {
        $context->identity->tenant->assertSame($this->ref('snapshot-1')->tenant);
        return new ContextState($this->ref('snapshot-1'), $this->revision, 'en', $this->ref('retention-1'),
            $this->ref('residency-1'), ['host' => $this->policy], $this->capability, $this->ref('education-session-1'));
    }
    public function slices(ExecutionContext $context, ContextState $state): array
    {
        $tenant = $this->sliceTenant === '' ? $context->identity->tenant : new TenantId($this->sliceTenant);
        $ref = static fn (string $id): Ref => new Ref($tenant, $id);
        return [new ContextBlock($ref('slice-1'), BlockKind::Evidence, $ref('pack-1'), 'v1', $ref('edufy-aicore'),
            TrustClass::Owner, Classification::Restricted, $this->slicePurpose, $ref('approved-summary-1'),
            $this->fixture['approved_slice'], new UtcInstant($this->expiry), false, 1, $this->hasher, [$ref('rights-1')])];
    }
    public function permits(ExecutionContext $context, ContextBlock $block): bool { return $this->sliceAllowed && $this->allowed && !$this->emergency; }
    public function now(): UtcInstant { return new UtcInstant($this->instant); }
    public function ref(string $id): Ref
    {
        return new Ref($this->active ?? throw new ContractViolation(ErrorCode::Unauthenticated), $id);
    }
    public function reset(): void { $this->active = null; ++$this->resets; }
}
