<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, InputPayload, PayloadHash, Ref, SourceIdentity};
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};

/** Stateless ingress projection; durable deduplication belongs exclusively to ordinary intake. */
final readonly class Boundary
{
    public function __construct(private Owner $owner, private CanonicalHasher $hasher) {}

    public function open(string $handle): Envelope
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}\z/D', $handle) !== 1) {
            throw new \InvalidArgumentException('Opaque host input handle required');
        }
        $request = $this->owner->request($handle);
        $context = ExecutionContext::fromHost($this->owner, $request->session, $request->purpose, $request->correlation);
        $this->owner->assertPermitted($context, $request);
        $transcript = $request->transcript;
        if (!$transcript->final) { throw new Rejection('INTERIM_TRANSCRIPT'); }
        $media = $this->owner->media($context);
        $context->session->tenant->assertSame($media->realtimeSession->tenant);
        if ($media->epoch !== $transcript->mediaEpoch || $media->realtimeSession->value !== $transcript->realtimeSession->value) {
            throw new Rejection('STALE_MEDIA_EPOCH');
        }
        if ($media->contextRevision !== $transcript->contextRevision) {
            throw new Rejection('STALE_CONTEXT_REVISION', $media->contextRevision);
        }
        $tenant = $context->session->tenant;
        $identity = substr($this->hasher->hash($tenant, 'voice-utterance', [
            'session' => $request->session->toArray(), 'utterance_id' => $transcript->utteranceId])->value, 8);
        $source = new SourceIdentity($request->instance, 'voice-utterance', $identity);
        $input = new TrustedInput($context, $source, new InputPayload(new Ref($tenant, 'voice-input:'.$identity),
            $this->hasher->hash($tenant, 'voice-final-content', $transcript->content()), $request->conversation,
            utteranceRef: new Ref($tenant, 'voice-utterance:'.$identity)));
        return new Envelope($handle, $input, $media, $this->fingerprint($input, $media));
    }

    /** Rehydrate at admission/IO boundaries; a pinned envelope never grants ongoing permission. */
    public function assertCurrent(Envelope $envelope): void
    {
        $fresh = $this->open($envelope->handle);
        $envelope->input->context->identity->tenant->assertSame($fresh->input->context->identity->tenant);
        $candidate = $this->fingerprint($envelope->input, $envelope->media);
        if ($candidate->value !== $envelope->fingerprint->value || $candidate->value !== $fresh->fingerprint->value) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }

    private function fingerprint(TrustedInput $input, MediaState $media): PayloadHash
    {
        return $this->hasher->hash($input->context->session->tenant, 'voice-envelope', [
            'scope' => $input->context->scopeHash($this->hasher)->value, 'source' => $input->source->toArray(),
            'payload' => $input->payload->toArray(), 'media' => $media->toArray()]);
    }
}
