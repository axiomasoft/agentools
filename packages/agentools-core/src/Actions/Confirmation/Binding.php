<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, PayloadHash, UtcInstant};

/** Immutable platform tuple; its hash belongs in the dated descriptor digest. */
final readonly class Binding
{
    public PayloadHash $hash;

    public function __construct(public ActionId $action, private ActionSubmission $submission,
        public int $version, public int $epoch, public UtcInstant $expiresAt,
        public PayloadHash $previewHash, CanonicalHasher $hasher)
    {
        $action->tenant->assertSame($submission->request->context->identity->tenant);
        if ($version < 1 || $epoch < 0 || max($version,$epoch)>9007199254740991) {
            throw new \InvalidArgumentException('A positive action version and nonnegative epoch are required');
        }
        $this->hash = $hasher->hash($action->tenant, 'confirmation-binding', $this->toArray());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $request = $this->submission->request;
        return ['action_ref' => $this->action->toArray(), 'action_version' => $this->version, 'epoch' => $this->epoch,
            'definition_ref' => $request->definition->toArray(), 'input_hash' => $request->inputHash->value,
            'scope_hash' => $request->scopeHash->value, 'actor_ref' => $request->context->identity->actorRef->toArray(),
            'policy_revision' => $request->context->identity->policyRevision, 'request_hash' => $request->requestHash->value,
            'expires_at' => $this->expiresAt->value, 'preview_hash' => $this->previewHash->value];
    }
}
