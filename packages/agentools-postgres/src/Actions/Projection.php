<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenTools\Action\FoldedAction;
use AgenTools\Schema\Canonicalizer;

/** Derived entirely by the dated reducer; no second lifecycle model or journal. */
final readonly class Projection
{
    public function __construct(public FoldedAction $state)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $effects = [];
        foreach ($this->state->effects as $key => $effect) {
            $effects[$key] = ['effect_id' => $effect->effectId, 'status' => $effect->status->value,
                'epoch' => $effect->epoch, 'requested_seq' => $effect->requestedSeq, 'resolved_seq' => $effect->resolvedSeq,
                'tool_name' => $effect->toolName, 'idempotency_key' => $effect->idempotencyKey, 'external_ref' => $effect->externalRef];
        }
        return ['action_id' => $this->state->actionId, 'status' => $this->state->status?->value,
            'seq' => $this->state->seq, 'epoch' => $this->state->epoch, 'inputs' => (object) $this->state->inputs,
            'effects' => (object) $effects, 'cancellation_requested' => $this->state->cancellationRequested,
            'previous_hash' => $this->state->previousHash];
    }

    public function json(): string
    {
        return (new Canonicalizer())->canonicalize($this->toArray());
    }

    public function digest(): string
    {
        return 'sha-256:' . hash('sha256', $this->json());
    }
}
