<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\Actor;
use AgenTools\Contracts\AuditSink;
use AgenTools\Contracts\Clock;
use AgenTools\Contracts\EventStore;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Persistence\UncommittedEvent;

/** Appends journal events through EventStore. Storage assigns seq. */
final class JournalAppender
{
    private ActionJournal $journal;

    public function __construct(
        private readonly EventStore $store,
        private readonly string $tenantId,
        private readonly string $actionId,
        private readonly Actor $actor,
        private readonly Clock $clock,
        private readonly int $epoch = 1,
        private readonly string $specRevision = '2026-08',
        private readonly ?ActionReducer $reducer = null,
        private readonly ?AuditSink $auditSink = null,
        private readonly AuditRedaction $redaction = new AuditRedaction(),
        ?ActionJournal $journal = null,
    ) {
        if ($tenantId === '' || $actionId === '') {
            throw EventStoreFailure::streamIdentity();
        }
        $this->journal = $journal ?? new ActionJournal();
    }

    public function journal(): ActionJournal
    {
        return $this->journal;
    }

    public function store(): EventStore
    {
        return $this->store;
    }

    public static function newEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param array<string, mixed> $payload */
    public function append(string $kind, array $payload = [], ?int $epoch = null): self
    {
        if (str_starts_with($kind, 'audit.')) {
            $this->redaction->assertPayload($payload);
        }
        $events = $this->journal->events();
        $expected = $events === [] ? 0 : $events[array_key_last($events)]->seq;
        $uncommitted = new UncommittedEvent(
            eventId: self::newEventId(),
            kind: $kind,
            eventVersion: 1,
            specRevision: $this->specRevision,
            actor: $this->actor,
            timestamp: $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            payload: $payload,
            epoch: $epoch ?? ($this->needsEpoch($kind) ? $this->epoch : null),
        );
        $this->store->append($this->tenantId, $this->actionId, $expected, [$uncommitted]);
        $page = $this->store->load($this->tenantId, $this->actionId, $expected, 1);
        foreach ($page->events as $committed) {
            $this->journal = $this->journal->append($committed, $this->reducer);
            $this->exportAudit($committed);
        }

        return $this;
    }

    private function exportAudit(ActionEvent $event): void
    {
        if (!str_starts_with($event->kind, 'audit.') || $this->auditSink === null) {
            return;
        }
        try {
            $this->auditSink->export($this->redaction->redactDocument($event->toArray()));
        } catch (\Throwable) {
        }
    }

    private function needsEpoch(string $kind): bool
    {
        return in_array($kind, ['effect_requested', 'effect_confirmed', 'effect_failed', 'epoch_started'], true);
    }
}
