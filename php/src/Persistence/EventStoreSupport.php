<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Error\EventStoreFailure;
use AgenTools\Schema\Canonicalizer;

/** Shared append/load bounds and classify-before-insert checks for EventStore adapters. */
final class EventStoreSupport
{
    public const DEFAULT_PAGE = 500;
    public const MAX_PAGE = 1000;
    public const MAX_BATCH = 64;

    private const AUDIT_ALLOW = [
        'tool_name' => true, 'side_effect' => true, 'verdict' => true,
        'limit_name' => true, 'limit_value' => true, 'observed' => true,
        'scenario_id' => true, 'outcome' => true, 'step_id' => true,
        'tokens_in' => true, 'tokens_out' => true, 'amount_minor' => true,
        'currency' => true, 'price_table_version' => true, 'trace' => true,
    ];

    public function __construct(private readonly Canonicalizer $canonicalizer = new Canonicalizer())
    {
    }

    /** @param list<UncommittedEvent> $events */
    public function assertAppendable(string $tenantId, string $actionId, array $events): void
    {
        if ($tenantId === '' || $actionId === '') {
            throw EventStoreFailure::streamIdentity();
        }
        if ($events === []) {
            throw EventStoreFailure::batchEmpty();
        }
        if (count($events) > self::MAX_BATCH) {
            throw EventStoreFailure::batchLimit(count($events));
        }
        $seen = [];
        foreach ($events as $event) {
            if (isset($seen[$event->eventId])) {
                throw EventStoreFailure::duplicateInBatch($event->eventId);
            }
            $seen[$event->eventId] = true;
            if ($this->isSeqDerived($event->eventId, $actionId)) {
                throw EventStoreFailure::seqDerivedEventId($event->eventId);
            }
            $this->assertAuditPayload($event);
        }
    }

    public function contentHash(string $tenantId, string $actionId, UncommittedEvent $event): string
    {
        $envelope = [
            'action_id' => $actionId,
            'actor' => $event->actor->toArray(),
            'event_id' => $event->eventId,
            'event_version' => $event->eventVersion,
            'kind' => $event->kind,
            'payload' => $event->payload,
            'spec_rev' => $event->specRevision,
            'tenant_id' => $tenantId,
            'ts' => $event->timestamp,
        ];
        if ($event->epoch !== null) {
            $envelope['epoch'] = $event->epoch;
        }

        return 'sha-256:' . hash('sha256', $this->canonicalizer->canonicalize($envelope));
    }

    private function isSeqDerived(string $eventId, string $actionId): bool
    {
        return (bool) preg_match('/^ev-.+-\d+$/', $eventId)
            || str_starts_with($eventId, 'ev-' . $actionId . '-');
    }

    private function assertAuditPayload(UncommittedEvent $event): void
    {
        if (!str_starts_with($event->kind, 'audit.')) {
            return;
        }
        foreach ($event->payload as $key => $value) {
            $name = (string) $key;
            $allowed = isset(self::AUDIT_ALLOW[$name])
                || str_ends_with($name, '_id')
                || str_ends_with($name, '_digest');
            if (!$allowed || in_array($name, ['arguments', 'args', 'prompt', 'prompts', 'token', 'email', 'pii', 'password'], true)) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
            if ($name === 'amount_minor' && is_float($value)) {
                throw EventStoreFailure::auditForbiddenField($name);
            }
        }
    }
}
