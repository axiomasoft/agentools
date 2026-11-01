<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InstanceId, PayloadHash, Ref};
use AgenToolsPlatform\Core\Context\ContextTarget;
use AgenToolsPlatform\Core\Models\Deployment;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{SessionBinding, SessionRepository};

/** Code configuration pins through native SessionRepository, not a profile publication or authority grant. */
final readonly class TextProfile
{
    public PayloadHash $hash;
    public BundleRef $bundle;

    public function __construct(public InstanceId $assistant, public string $version, public string $policy,
        public string $system, public Deployment $deployment, public Ref $credential,
        public ContextTarget $target, CanonicalHasher $hasher)
    {
        foreach ([$deployment->ref, $credential, $target->deployment] as $ref) { $assistant->tenant->assertSame($ref->tenant); }
        $deployment->ref->assertSame($target->deployment);
        if ($version === '' || $policy === '' || $system === '' || $target->requestedMaxOutput < 1) {
            throw new \InvalidArgumentException('An explicit code-owned text profile and deployment are required');
        }
        $capabilities = [];
        foreach ($deployment->capabilities as $name => $evidence) {
            $capabilities[$name] = ['support' => $evidence->support->value, 'evidence' => $evidence->evidence->value,
                'checked_at' => $evidence->checkedAt->value, 'expires_at' => $evidence->expiresAt->value,
                'source' => $evidence->evidenceRef];
        }
        $this->hash = $hasher->hash($assistant->tenant, 'chatom-text-profile', [
            'assistant' => $assistant->toArray(), 'version' => $version, 'policy' => $policy, 'system' => $system,
            'deployment' => ['ref' => $deployment->ref->toArray(), 'provider' => $deployment->provider,
                'account' => $deployment->account, 'region' => $deployment->region, 'model' => $deployment->model, 'capabilities' => $capabilities],
            'credential_ref' => $credential->toArray(), 'window' => $target->window,
            'output' => $target->requestedMaxOutput, 'reserve' => $target->outputReserve, 'margin' => $target->safetyMargin,
            'model_version' => $target->modelVersion, 'capability_snapshot' => $target->capabilitySnapshot?->toArray(),
            'capability_hash' => $target->capabilityHash?->value, 'tools' => []]);
        $this->bundle = new BundleRef(new Ref($assistant->tenant, 'chatom:profile:'.substr($this->hash->value, 8)), 1, $this->hash);
    }

    public function bind(ExecutionContext $context, SessionRepository $sessions, CanonicalHasher $hasher): void
    {
        $sessions->bind($context, new SessionBinding($context->session, $this->assistant,
            new Ref($this->assistant->tenant, 'chatom:definition:'.substr($this->hash->value, 8)), $this->hash,
            $hasher->hash($this->assistant->tenant, 'chatom-text-registry', []), $this->bundle->ref, $this->bundle->hash,
            new Ref($context->session->tenant, $context->session->value)));
    }
}
