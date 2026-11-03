<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Security\{BoundaryClock, ExecutionContext, TrustedInput};
use AgenToolsPlatform\Laravel\Managed\Input;

/** No singleton principal/cache and no execution loop. Owner checks remain authoritative. */
final readonly class Boundary
{
    public function __construct(private HostOwner $owner, private BoundaryClock $clock, private CanonicalHasher $hasher) {}

    public function open(Input $handle): Envelope
    {
        $request = $this->owner->request($handle);
        $context = ExecutionContext::fromHost($this->owner, $request->session, $request->purpose, $request->correlation);
        $input = new TrustedInput($context, $request->source, $request->payload);
        $state = $this->owner->state($context);
        $context->identity->tenant->assertSame($state->snapshot->tenant);
        $slices = $this->owner->slices($context, $state);
        if (!array_is_list($slices) || count($slices) > 32) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $seen = [];
        foreach ($slices as $slice) {
            $context->identity->tenant->assertSame($slice->ref->tenant);
            if (isset($seen[$slice->ref->value]) || $slice->purpose !== $context->purpose
                || new \DateTimeImmutable($slice->expiresAt->value) <= new \DateTimeImmutable($this->clock->now()->value)
                || strlen($slice->modelView) > 16384 || !$this->owner->permits($context, $slice)) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            $seen[$slice->ref->value] = true;
        }
        $fingerprint = $this->hasher->hash($context->identity->tenant, 'host-envelope', [
            'scope' => $context->scopeHash($this->hasher)->value, 'state' => $state->toArray(),
            'source' => $input->source->toArray(), 'payload' => $input->payload->toArray(),
            'slices' => array_map(static fn ($slice): array => $slice->manifest(), $slices)]);
        return new Envelope($handle, $input, $state, $slices, $fingerprint);
    }

    public function assertCurrent(Envelope $envelope): void
    {
        $fresh = $this->open($envelope->handle);
        $envelope->input->context->identity->tenant->assertSame($fresh->input->context->identity->tenant);
        // Recompute the supplied value too: a caller-created envelope cannot borrow a valid fingerprint.
        $candidate = $this->hasher->hash($envelope->input->context->identity->tenant, 'host-envelope', [
            'scope' => $envelope->input->context->scopeHash($this->hasher)->value, 'state' => $envelope->state->toArray(),
            'source' => $envelope->input->source->toArray(), 'payload' => $envelope->input->payload->toArray(),
            'slices' => array_map(static fn ($slice): array => $slice->manifest(), $envelope->slices)]);
        if ($candidate->value !== $fresh->fingerprint->value || $candidate->value !== $envelope->fingerprint->value) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }

    public function reset(): void { $this->owner->reset(); }
}
