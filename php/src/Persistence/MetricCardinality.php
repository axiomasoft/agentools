<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Error\EventStoreFailure;

/** Closed low-cardinality metric labels. Tenant/action/event/trace ids are forbidden. */
final class MetricCardinality
{
    private const ALLOW = [
        'event_kind_class' => ['lifecycle', 'audit', 'effect'],
        'append_result' => ['appended', 'idempotent_replay', 'conflict', 'validation'],
        'conflict_class' => ['version', 'collision', 'partial'],
    ];

    private const DENY = [
        'tenant_id', 'action_id', 'event_id', 'trace_id', 'span_id', 'user_id',
    ];

    /** @param array<string, string> $labels */
    public function assertAllowed(array $labels): void
    {
        foreach ($labels as $name => $value) {
            if (in_array($name, self::DENY, true) || !isset(self::ALLOW[$name])) {
                throw EventStoreFailure::metricLabelForbidden((string) $name);
            }
            if (!in_array($value, self::ALLOW[$name], true)) {
                throw EventStoreFailure::metricLabelForbidden((string) $name);
            }
        }
    }
}
