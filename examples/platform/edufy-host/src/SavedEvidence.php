<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\EdufyHost;

use AgenToolsPlatform\Core\Actions\Effects\Request;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, UtcInstant};
use AgenToolsPlatform\Laravel\Host\{Envelope, ReportEvidence};

/** Scripted saved owner confirmation evidence; NOT a replacement ConfirmationGateway. */
final class SavedEvidence implements ReportEvidence
{
    /** @var array<string, array<string, mixed>> */
    private array $approved = [];
    public bool $cancelled = false;
    public int $epoch = 1;
    public int $checks = 0;
    public function __construct(private SchoolHost $host) {}
    /** Trusted fixture simulates a saved affirmative UI event from the existing Actions gateway. */
    public function affirmative(Envelope $envelope, Request $request, Ref $actor, Ref $event, string $channel, UtcInstant $expiry): void
    {
        $request->action->tenant->assertSame($actor->tenant); $actor->tenant->assertSame($event->tenant);
        if ($channel !== 'ui' || $actor->value !== $envelope->input->context->identity->actorRef->value) {
            throw new ContractViolation(ErrorCode::ConfirmationInvalid);
        }
        $this->approved[$request->action->tenant->value.':'.$request->operationKey] = ['action' => $request->action->value, 'hash' => $request->requestHash->value,
            'input' => $request->requestRef->value, 'event' => $event->value, 'actor' => $actor->value,
            'effect' => $request->effectKey, 'namespace' => $request->ownerNamespace,
            'snapshot' => $envelope->fingerprint->value, 'expiry' => $expiry->value, 'epoch' => $request->requestedEpoch];
    }
    public function assertCurrent(Envelope $envelope, Request $request): void
    {
        ++$this->checks;
        $saved = $this->approved[$request->action->tenant->value.':'.$request->operationKey] ?? null;
        if ($saved === null) { throw new ContractViolation(ErrorCode::ConfirmationRequired); }
        if ($this->cancelled || $request->requestedEpoch !== $this->epoch || $saved['epoch'] !== $this->epoch
            || $saved['action'] !== $request->action->value || $saved['hash'] !== $request->requestHash->value
            || $saved['effect'] !== $request->effectKey || $saved['namespace'] !== $request->ownerNamespace
            || $saved['input'] !== $request->requestRef->value || $saved['actor'] !== $envelope->input->context->identity->actorRef->value
            || $saved['snapshot'] !== $envelope->fingerprint->value
            || new \DateTimeImmutable($saved['expiry']) <= new \DateTimeImmutable($this->host->now()->value)) {
            throw new ContractViolation(ErrorCode::ConfirmationInvalid);
        }
    }
}
