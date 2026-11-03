<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Examples\SupportHost;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, InputPayload, InstanceId, Ref, SessionId, SourceIdentity, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextBlock;
use AgenToolsPlatform\Core\Security\{BoundaryClock, Correlation, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Laravel\Host\{ContextState, HostOwner, HostRequest};
use AgenToolsPlatform\Laravel\Managed\Input;

/** Scripted support owner; only approved projections cross the host boundary. */
final class Host implements HostOwner, BoundaryClock
{
    public bool $allowed = true;
    public bool $emergency = false;
    public int $revision = 1;
    public function __construct(public readonly CanonicalHasher $hasher, public readonly TenantId $tenant) {}
    public function ref(string $id): Ref { return new Ref($this->tenant, $id); }
    public function request(Input $handle): HostRequest
    {
        if ($handle->ref !== 'ticket-request') { throw new ContractViolation(ErrorCode::Unauthenticated); }
        return new HostRequest(new SessionId($this->tenant, 'support-session'),
            new SourceIdentity(new InstanceId($this->tenant, 'support-assistant'), 'support', 'event-1'),
            new InputPayload($this->ref('input-1'), $this->hasher->hash($this->tenant, 'support-input', ['ticket'=>'T-1'])),
            'support.read', new Correlation('support-trace', 'support-request'));
    }
    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        $this->tenant->assertSame($session->tenant);
        if (!$this->allowed || $this->emergency || $purpose !== 'support.read') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant, $this->ref('actor'), null, $this->ref('support-authorization'), 'support-policy-v1');
    }
    public function state(ExecutionContext $context): ContextState
    {
        return new ContextState($this->ref('support-snapshot'), $this->revision, 'en', $this->ref('retention'),
            $this->ref('residency'), ['support'=>'v1'], ['support.ticket.read', 'support.ticket.update']);
    }
    public function slices(ExecutionContext $context, ContextState $state): array
    {
        return [new ContextBlock($this->ref('support-slice'), 'evidence', $this->ref('ticket-T-1'), '1',
            $this->ref('support-owner'), 'owner', 'private', 'support.read', $this->ref('ticket-view'),
            'Ticket T-1 is open.', new UtcInstant('2031-01-01T00:00:00Z'), false, 1, $this->hasher)];
    }
    public function permits(ExecutionContext $context, ContextBlock $block): bool { return $this->allowed && !$this->emergency; }
    public function now(): UtcInstant { return new UtcInstant('2026-11-03T00:00:00Z'); }
    public function reset(): void {}
}
