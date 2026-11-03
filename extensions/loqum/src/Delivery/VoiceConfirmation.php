<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\{ConfirmationAuthority, Decision, Preview};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode};

/** Adapter to existing hardened confirmation; no token codec, approval rows or effect execution here. */
final readonly class VoiceConfirmation implements ConfirmationAuthority
{
    public function __construct(private ApprovalOwner $owner,private CanonicalHasher $hasher) {}
    public function presentation(ActionSubmission $submission): string { return $this->owner->presentation($submission); }
    public function decision(Preview $preview): ?Decision
    {
        $event=$this->owner->event($preview);
        if ($event===null) { return null; }
        $context=$this->owner->context($preview);$context->assertCurrent($this->owner);
        $tenant=$context->session->tenant;$tenant->assertSame($event->source->tenant);$tenant->assertSame($preview->proposal->tenant);
        $tuple=$preview->binding->toArray();$media=$this->owner->media($context);
        $tenant->assertSame($media->realtimeSession->tenant);
        if ($event->session->value!==$context->session->value || $event->actor->value!==$context->identity->actorRef->value
            || $event->proposal->value!==$preview->proposal->value || $event->bindingHash->value!==$preview->binding->hash->value
            || $event->previewHash->value!==$preview->binding->previewHash->value || $event->inputHash->value!==$tuple['input_hash']
            || $event->policyRevision!==$context->identity->policyRevision || $event->policyRevision!==$tuple['policy_revision']
            || $tuple['scope_hash']!==$context->scopeHash($this->hasher)->value || $media->epoch!==$event->mediaEpoch
            || $media->contextRevision!==$event->contextRevision
            || new \DateTimeImmutable($event->expiresAt->value)<=new \DateTimeImmutable($this->owner->now()->value)
            || new \DateTimeImmutable($event->expiresAt->value)>new \DateTimeImmutable($preview->binding->expiresAt->value)) {
            throw new ContractViolation(ErrorCode::ConfirmationInvalid);
        }
        $this->owner->assertPresented($preview,$event);
        $context->assertCurrent($this->owner);
        return new Decision($event->source,$this->hasher->hash($tenant,'voice-approval-event',$event->manifest()),
            $event->actor,$event->bindingHash,'voice',$event->expiresAt);
    }
}
