<?php

declare(strict_types=1);

namespace AgenTools\Error;

final class EventStoreFailure extends ToolExecutionException
{
    public static function batchEmpty(): self
    {
        return self::of(ToolErrorCode::EventBatchEmpty, 'The event batch must not be empty.');
    }

    public static function batchLimit(int $size): self
    {
        return self::of(ToolErrorCode::EventBatchLimit, 'The event batch exceeds the maximum of 64.', ['size' => $size]);
    }

    public static function duplicateInBatch(string $eventId): self
    {
        return self::of(ToolErrorCode::EventIdDuplicateInBatch, 'The batch contains a duplicate event_id.', ['event_id' => $eventId]);
    }

    public static function seqDerivedEventId(string $eventId): self
    {
        return self::of(ToolErrorCode::EventIdSeqDerived, 'event_id must not be derived from seq.', ['event_id' => $eventId]);
    }

    public static function streamIdentity(): self
    {
        return self::of(ToolErrorCode::EventStreamIdentity, 'tenant_id and action_id are required.');
    }

    public static function partialOverlap(): self
    {
        return self::of(ToolErrorCode::EventBatchPartialOverlap, 'The batch partially overlaps already-committed events.');
    }

    public static function auditForbiddenField(string $field): self
    {
        return self::of(ToolErrorCode::AuditForbiddenField, 'The audit payload contains a forbidden field.', ['field' => $field]);
    }

    public static function metricLabelForbidden(string $label): self
    {
        return self::of(ToolErrorCode::MetricLabelForbidden, 'The metric label set is not closed.', ['label' => $label]);
    }

    public static function pageLimit(int $limit): self
    {
        return self::of(ToolErrorCode::EventPageLimit, 'The event page limit must be between 1 and 1000.', ['limit' => $limit]);
    }

    /** @param array<string, mixed> $context */
    private static function of(ToolErrorCode $code, string $title, array $context = []): self
    {
        $parts = explode('.', $code->value);
        return new self(new ToolError(
            $code->value,
            $parts[1],
            false,
            $title,
            context: $context === [] ? null : $context,
        ));
    }
}
