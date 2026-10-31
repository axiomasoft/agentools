<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\TenantId;

/** Evidence returned only by the host authorization port; not a permanent grant. */
final readonly class HostIdentity
{
    public function __construct(
        public TenantId $tenant,
        public Ref $actorRef,
        public ?Ref $actingAs,
        public Ref $authorizationRef,
        public string $policyRevision,
        public ?Ref $subjectRef = null,
        public ?Ref $consentRef = null,
        public ?Ref $agentPrincipalRef = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $policyRevision) !== 1) {
            throw new \InvalidArgumentException('An explicit policy revision is required');
        }
        foreach ([$actorRef, $actingAs, $authorizationRef, $subjectRef, $consentRef, $agentPrincipalRef] as $ref) {
            if ($ref !== null) {
                $tenant->assertSame($ref->tenant);
            }
        }
    }
}
