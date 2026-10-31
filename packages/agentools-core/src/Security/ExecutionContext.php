<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\SessionId;

/** Immutable evidence snapshot. Every owner must reauthorize at write/disclosure. */
final readonly class ExecutionContext
{
    private function __construct(
        public HostIdentity $identity,
        public SessionId $session,
        public string $purpose,
        public Correlation $correlation,
    ) {
        $identity->tenant->assertSame($session->tenant);
    }

    public static function fromHost(HostAuthorization $host, SessionId $session, string $purpose, Correlation $correlation): self
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('A bounded purpose is required');
        }
        return new \AgenToolsPlatform\Core\Security\ExecutionContext($host->authorize($session, $purpose), $session, $purpose, $correlation);
    }

    public function assertCurrent(HostAuthorization $host): void
    {
        $current = $host->authorize($this->session, $this->purpose);
        $this->identity->tenant->assertSame($current->tenant);
        if ($this->identity->actorRef->value !== $current->actorRef->value) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
        }
        foreach ([[$this->identity->actingAs, $current->actingAs],
            [$this->identity->subjectRef, $current->subjectRef],
            [$this->identity->consentRef, $current->consentRef],
            [$this->identity->agentPrincipalRef, $current->agentPrincipalRef]] as [$pinned, $now]) {
            if (($pinned === null) !== ($now === null)) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
            }
            if ($pinned !== null && $now !== null && $pinned->value !== $now->value) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
            }
        }
        if ($this->identity->policyRevision !== $current->policyRevision) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext);
        }
    }

    public function scopeHash(CanonicalHasher $hasher): PayloadHash
    {
        $scope = $this->toArray();
        unset($scope['correlation']);
        return $hasher->hash($this->identity->tenant, 'execution-scope', $scope);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tenant_id' => $this->identity->tenant->value, 'actor_ref' => $this->identity->actorRef->toArray(),
            'acting_as' => $this->identity->actingAs?->toArray(), 'subject_ref' => $this->identity->subjectRef?->toArray(),
            'authorization_ref' => $this->identity->authorizationRef->toArray(), 'policy_revision' => $this->identity->policyRevision,
            'consent_ref' => $this->identity->consentRef?->toArray(), 'agent_principal_ref' => $this->identity->agentPrincipalRef?->toArray(),
            'session_ref' => $this->session->toArray(), 'purpose' => $this->purpose, 'correlation' => $this->correlation->toArray()];
    }
}
