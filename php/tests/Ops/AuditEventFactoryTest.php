<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Action\Actor;
use AgenTools\Contracts\AuditSink;
use AgenTools\Ops\AuditEventFactory;
use AgenTools\Persistence\FixedClock;
use AgenTools\Schema\PublishedSchemaValidator;
use PHPUnit\Framework\TestCase;

final class AuditEventFactoryTest extends TestCase
{
    /** AOB-1, AOB-2, AOB-9; audit events live in the action journal with audit.* kinds. */
    public function testItBuildsPolicyEvaluatedAuditEvent(): void
    {
        $exported = [];
        $sink = new class($exported) implements AuditSink {
            /** @param list<array<string, mixed>> $exported */
            public function __construct(public array &$exported)
            {
            }

            public function export(array $auditEvent): void
            {
                $this->exported[] = $auditEvent;
            }
        };
        $factory = new AuditEventFactory(
            new FixedClock(new \DateTimeImmutable('2026-08-05T12:00:00+00:00')),
            new PublishedSchemaValidator(__DIR__ . '/../../../spec/revisions/2026-08/schema'),
            $sink,
        );

        $event = $factory->policyEvaluated(
            'act-audit-1',
            2,
            1,
            new Actor('agent', 'agent-1'),
            'crm_report_list',
            'read',
            'allow',
        );

        self::assertSame('audit.policy_evaluated', $event->kind);
        self::assertSame('crm_report_list', $event->payload['tool_name']);
        self::assertCount(1, $exported);
    }

    /** AOB-3; audit events must not drive lifecycle transitions. */
    public function testAuditEventsDoNotChangeReducerProjection(): void
    {
        $journal = new \AgenTools\Action\ActionJournal([
            new \AgenTools\Action\ActionEvent('ev-1', 'act-1', 1, 0, '2026-08-05T10:00:00+00:00', new Actor('agent', 'a1'), 'action_created', 1, '2026-08', []),
        ]);
        $journal = $journal->append(new \AgenTools\Action\ActionEvent(
            'ev-2',
            'act-1',
            2,
            1,
            '2026-08-05T10:00:01+00:00',
            new Actor('agent', 'a1'),
            'audit.policy_evaluated',
            1,
            '2026-08',
            ['tool_name' => 'demo', 'side_effect' => 'read', 'verdict' => 'allow'],
        ));

        self::assertSame(\AgenTools\Action\ActionStatus::Created, $journal->state()->status);
    }

    /** AOB-10, AOB-15; cost audit stores integer minor units and currency. */
    public function testItBuildsCostRecordedAuditEvent(): void
    {
        $factory = new AuditEventFactory(new FixedClock(new \DateTimeImmutable('2026-08-05T12:00:00+00:00')));
        $payload = (new \AgenTools\Ops\CostRecorder())->costPayload(100, 50, 'USD', 'pt-2026-08', ['input_minor' => 2, 'output_minor' => 3]);
        $event = $factory->costRecorded('act-1', 3, 2, new Actor('agent', 'a1'), $payload);

        self::assertSame('audit.cost_recorded', $event->kind);
        self::assertSame(350, $event->payload['amount_minor']);
        self::assertSame('USD', $event->payload['currency']);
    }
}
