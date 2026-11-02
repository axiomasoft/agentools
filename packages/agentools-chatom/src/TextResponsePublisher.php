<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenTools\Chatom\Bridge\BridgeKeys;
use AgenTools\Chatom\Data\DeliveryIntent;
use AgenTools\Chatom\Data\IncomingInput;
use AgenTools\Chatom\Data\ResponseIdentity;
use AgenTools\Chatom\Nodes\Shape;
use AgenTools\Chatom\TranscriptNodes;
use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Models\FinishReason;
use AgenToolsPlatform\Core\Runtime\ExchangeResult;
use AgenToolsPlatform\Core\Runtime\InvocationCheckpoint;
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Runtime\InvocationPlan;
use AgenToolsPlatform\Core\Runtime\ResponsePublication;
use AgenToolsPlatform\Core\Runtime\ResponsePublisher;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use Chatom\Channels\Data\ChannelId;
use Chatom\Documents\Data\MessageDocument;
use Closure;

/** The only text egress conversion. Raw model parts never become unchecked Chatom nodes. */
final readonly class TextResponsePublisher implements ResponsePublisher
{
    /** @param Closure(string):ExecutionContext $context Reconstruct current host authority at each purpose.
     * @param  Closure(ExecutionContext,Ref,string):void  $disclosure  Current owning output policy before export.
     */
    public function __construct(private InvocationPayloads $raw, private InvocationPayloads $exports,
        private CanonicalHasher $hasher, private Closure $context, private Closure $disclosure,
        private ?TextPayloadLineage $lineage = null, private ?NativeHistory $citationHistory = null,
        private ?OwnerReferenceProducer $ownerReferences = null) {}

    public function prepare(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $checkpoint, Ref $response): ResponsePublication
    {
        $saved = $checkpoint->data['result_ref'] ?? null;
        $hash = $checkpoint->data['result_hash'] ?? null;

        if (! is_string($saved) || ! is_string($hash) || $checkpoint->stage() !== 'response') {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        $json = $this->raw->get($context, new Ref($response->tenant, $saved));
        new PayloadHash($hash)->assertSame(new PayloadHash('sha-256:'.hash('sha256', $json)));
        $exchange = ExchangeResult::decode($plan, $json, $this->hasher);

        if (! $exchange->accepted() || $exchange->finish !== FinishReason::Completed || $exchange->proposals !== []) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        $data = Shape::record(json_decode($json, true, 128, JSON_THROW_ON_ERROR));
        $reply = Shape::record($data['response'] ?? null);
        $parts = $reply['parts'] ?? null;
        $text = '';

        if (! is_array($parts) || ! array_is_list($parts)) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        foreach ($parts as $part) {
            if (! is_array($part) || ($part['kind'] ?? null) !== 'text' || ! is_string($part['text'] ?? null)
                || ($part['ref'] ?? null) !== null) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            $text .= $part['text'];
        }

        if (trim($text) === '' || strlen($text) > 65536) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        $input = IncomingInput::fromArray(Shape::record(json_decode($this->raw->get($context, $plan->businessOperation), true, 64, JSON_THROW_ON_ERROR)));
        $delivery = ($this->context)('chatom.delivery');
        $context->session->assertSame($delivery->session);

        if ($context->identity->policyRevision !== $delivery->identity->policyRevision
            || $input->target->policyRevision !== $delivery->identity->policyRevision) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $text = "ИИ-ассистент\n".$text;
        ($this->disclosure)($delivery, $response, $text);
        $references = $this->citationHistory instanceof NativeHistory ? [TranscriptNodes::citation(
            $this->citationHistory->citation($delivery, $input), $input->revision, 'input-source')] : [];
        $owned = OwnerReference::batch($this->ownerReferences?->export($delivery, $input) ?? []);
        foreach ($owned as $index => $reference) {
            $references[] = $reference->node('owner-reference-'.$index);
        }
        // The policy may perform owner IO. Rehydrate authority again before storing the visible export.
        $current = ($this->context)('chatom.delivery');
        $current->session->assertSame($delivery->session);

        if ($current->identity->policyRevision !== $delivery->identity->policyRevision
            || $current->identity->actorRef->value !== $delivery->identity->actorRef->value
            || $current->identity->subjectRef?->value !== $delivery->identity->subjectRef?->value) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $identity = ResponseIdentity::fromArray(['assistant_ref' => $input->target->assistant,
            'session_ref' => $context->session->value, 'turn_ref' => $plan->turnId->value,
            'response_version' => 1, 'state' => 'final', 'supersedes' => null]);
        $intent = new DeliveryIntent($input->tenant, $input->space, ChannelId::from($input->source->channelId()),
            $response->value, $identity, new MessageDocument(1, [...MessageDocument::fromPlainText($text)->content, ...$references,
                TranscriptNodes::response($identity)]), $input, $delivery->identity->policyRevision);
        $payload = new Ref($response->tenant, 'payload:'.hash('sha256', $intent->key()));
        $bytes = BridgeKeys::encode($intent->export());
        $contentHash = new PayloadHash('sha-256:'.$intent->hash());
        $this->lineage?->inherit($delivery, new Ref($response->tenant, $saved), $payload, 'temporary-payloads');
        $this->exports->put($delivery, $payload, $contentHash, $bytes);

        return new ResponsePublication($payload, $contentHash, $intent->key());
    }
}
