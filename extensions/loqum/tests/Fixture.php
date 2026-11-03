<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Tests;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, InstanceId, PayloadHash, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, HostIdentity};
use AgenToolsPlatform\Loqum\Ingress\{Boundary, MediaState, Owner, Request, Transcript};
use AgenToolsPlatform\Tests\JcsAdapter;

final class Fixture implements Owner
{
    public TenantId $tenant;
    public CanonicalHasher $hasher;
    public Boundary $boundary;
    public Request $current;
    public MediaState $state;
    public bool $denied = false;
    public bool $voiceAllowed = true;
    public string $policy = 'policy-1';
    public string $actor = 'actor';
    public string $consent = 'consent';

    public function __construct(string $tenant = 'tenant-a')
    {
        $this->tenant = new TenantId($tenant);
        $this->hasher = new CanonicalHasher(new JcsAdapter());
        $this->boundary = new Boundary($this, $this->hasher);
        $this->state = new MediaState($this->ref('realtime:1'), 1, 1);
        $this->set();
    }

    public function ref(string $value): Ref { return new Ref($this->tenant, $value); }

    public function set(array $changes = [], string $session = 'session', string $instance = 'instance'): void
    {
        $values = ['eventId' => 'transport:event:1', 'realtimeSession' => $this->state->realtimeSession,
            'mediaEpoch' => $this->state->epoch, 'utteranceId' => 'utterance:1', 'revision' => 2,
            'locale' => 'ru-RU', 'startedAt' => new UtcInstant('2026-11-03T09:00:00Z'),
            'endedAt' => new UtcInstant('2026-11-03T09:00:02Z'), 'textRef' => $this->ref('immutable:text:1'),
            'textHash' => new PayloadHash('sha-256:'.hash('sha256', 'Переведи деньги')),
            'contextRevision' => 1, 'idempotencyKey' => 'transport:retry:1', 'final' => true, 'channel' => 'voice'];
        $this->current = new Request(new SessionId($this->tenant, $session), new InstanceId($this->tenant, $instance),
            new Transcript(...array_replace($values, $changes)), 'assist.read', new Correlation('trace', 'request'), $this->ref('chatom:conversation'));
    }

    public function request(string $handle): Request
    {
        if ($handle !== 'host:input') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return $this->current;
    }

    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        $this->tenant->assertSame($session->tenant);
        if ($this->denied || $purpose !== 'assist.read') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant, $this->ref($this->actor), null, $this->ref('authorization'),
            $this->policy, $this->ref('subject'), $this->ref($this->consent), $this->ref('agent'));
    }

    public function media(ExecutionContext $context): MediaState { return $this->state; }

    public function assertPermitted(ExecutionContext $context, Request $request): void
    {
        if ($request->transcript->channel === 'voice' && !$this->voiceAllowed) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }
}
