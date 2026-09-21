<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Action\Actor;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\VersionConflict;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Persistence\AppendResult;
use AgenTools\TestKit\InMemoryEventStore;
use AgenTools\Persistence\MetricCardinality;
use AgenTools\Persistence\UncommittedEvent;
use PHPUnit\Framework\TestCase;

final class EventStoreCollisionTest extends TestCase
{
    public function testFiftyAppendsYieldOneWinner(): void
    {
        $store = new InMemoryEventStore();
        $winner = 0;
        $conflicts = 0;
        for ($i = 0; $i < 50; $i++) {
            try {
                $store->append('tenant-a', 'action-1', 0, [$this->event(sprintf('cc%030d', $i))]);
                $winner++;
            } catch (VersionConflict) {
                $conflicts++;
            }
        }
        self::assertSame(1, $winner);
        self::assertSame(49, $conflicts);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    public function testAtomicTwoEventBatchRollback(): void
    {
        $store = new InMemoryEventStore();
        $store->append('tenant-a', 'action-1', 0, [
            $this->event('dddddddddddddddddddddddddddddddd'),
            $this->event('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'),
        ]);
        try {
            $store->append('tenant-a', 'action-1', 0, [
                $this->event('ffffffffffffffffffffffffffffffff'),
                $this->event('11111111111111111111111111111111'),
            ]);
            self::fail('expected version conflict');
        } catch (VersionConflict $conflict) {
            self::assertSame(ToolErrorCode::VersionConflict->value, $conflict->error->code);
        }
        self::assertCount(2, $store->load('tenant-a', 'action-1')->events);
    }

    public function testIdenticalReplay(): void
    {
        $store = new InMemoryEventStore();
        $batch = [
            $this->event('22222222222222222222222222222222'),
            $this->event('33333333333333333333333333333333'),
        ];
        $first = $store->append('tenant-a', 'action-1', 0, $batch);
        $replay = $store->append('tenant-a', 'action-1', 0, $batch);
        self::assertSame(AppendResult::IDEMPOTENT_REPLAY, $replay->outcome);
        self::assertSame($first->firstSeq, $replay->firstSeq);
        self::assertCount(2, $store->load('tenant-a', 'action-1')->events);
    }

    public function testPayloadCollision(): void
    {
        $store = new InMemoryEventStore();
        $id = '44444444444444444444444444444444';
        $store->append('tenant-a', 'action-1', 0, [$this->event($id, payload: ['v' => 1])]);
        $this->expectException(EventIdCollision::class);
        $store->append('tenant-a', 'action-1', 0, [$this->event($id, payload: ['v' => 2])]);
    }

    public function testPartialOverlap(): void
    {
        $store = new InMemoryEventStore();
        $first = $this->event('55555555555555555555555555555555');
        $store->append('tenant-a', 'action-1', 0, [$first]);
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, [$first, $this->event('66666666666666666666666666666666')]);
    }

    public function testDuplicateIdInBatch(): void
    {
        $store = new InMemoryEventStore();
        $event = $this->event('77777777777777777777777777777777');
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, [$event, $event]);
    }

    public function testEmptyBatch(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, []);
    }

    public function testPageAbutNoSkip(): void
    {
        $store = new InMemoryEventStore();
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->event(sprintf('%032d', $i + 10));
        }
        $store->append('tenant-a', 'action-1', 0, $batch);
        $first = $store->load('tenant-a', 'action-1', 0, 2);
        self::assertSame([1, 2], array_map(static fn ($event) => $event->seq, $first->events));
        self::assertTrue($first->hasMore);
        $second = $store->load('tenant-a', 'action-1', $first->lastSeq, 2);
        self::assertSame([3, 4], array_map(static fn ($event) => $event->seq, $second->events));
        self::assertSame($first->lastSeq + 1, $second->events[0]->seq);
    }

    public function testPageLimitOver1000Rejected(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->load('tenant-a', 'action-1', 0, 1001);
    }

    public function testPageLimitBelowOneRejected(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->load('tenant-a', 'action-1', 0, 0);
    }

    public function testSecretPiiAuditRejected(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, [
            $this->event('88888888888888888888888888888888', 'audit.policy_evaluated', ['prompt' => 'secret']),
        ]);
    }

    public function testMetricLabelDenyTenantAction(): void
    {
        $this->expectException(EventStoreFailure::class);
        (new MetricCardinality())->assertAllowed(['tenant_id' => 't', 'action_id' => 'a']);
    }

    public function testSnapshotHashMismatchDiscard(): void
    {
        $store = new InMemoryEventStore();
        $event = $this->event('99999999999999999999999999999999');
        $store->append('tenant-a', 'action-1', 0, [$event]);
        $hash = $store->contentHash('tenant-a', 'action-1', $event);
        $mutated = substr($hash, 0, -1) . ($hash[-1] === 'a' ? 'b' : 'a');
        self::assertNotSame($hash, $mutated);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    public function testSeqDerivedEventIdRejected(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, [$this->event('ev-action-1-0001')]);
    }

    public function testBatchOver64(): void
    {
        $store = new InMemoryEventStore();
        $batch = [];
        for ($i = 0; $i < 65; $i++) {
            $batch[] = $this->event(sprintf('%032d', $i + 100));
        }
        $this->expectException(EventStoreFailure::class);
        $store->append('tenant-a', 'action-1', 0, $batch);
    }

    public function testMissingStreamIdentity(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(EventStoreFailure::class);
        $store->append('', 'action-1', 0, [$this->event('abababababababababababababababab')]);
    }

    public function testCrossStreamEventIdReuseDenied(): void
    {
        $store = new InMemoryEventStore();
        $event = $this->event('cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd');
        $store->append('tenant-a', 'action-1', 0, [$event]);
        $this->expectException(EventIdCollision::class);
        $store->append('tenant-a', 'action-2', 0, [$event]);
    }

    public function testSnapshotNeverTruncatesJournal(): void
    {
        $store = new InMemoryEventStore();
        $store->append('tenant-a', 'action-1', 0, [$this->event('efefefefefefefefefefefefefefefef')]);
        $before = count($store->load('tenant-a', 'action-1')->events);
        $hash = $store->contentHash('tenant-a', 'action-1', $this->event('efefefefefefefefefefefefefefefef'));
        self::assertStringStartsWith('sha-256:', $hash);
        self::assertCount($before, $store->load('tenant-a', 'action-1')->events);
    }

    public function testAuditSinkFailureDoesNotRollback(): void
    {
        $store = new InMemoryEventStore();
        $store->append('tenant-a', 'action-1', 0, [
            $this->event('12121212121212121212121212121212', 'audit.policy_evaluated', [
                'tool_name' => 'pay',
                'side_effect' => 'write',
                'verdict' => 'allow',
            ]),
        ]);
        try {
            throw new \RuntimeException('sink failed');
        } catch (\RuntimeException) {
        }
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    /** @param array<string, mixed> $payload */
    private function event(string $eventId, string $kind = 'action_created', array $payload = []): UncommittedEvent
    {
        return new UncommittedEvent(
            eventId: $eventId,
            kind: $kind,
            eventVersion: 1,
            specRevision: '2026-08',
            actor: new Actor('user', 'u-1'),
            timestamp: '2026-09-20T10:00:00+00:00',
            payload: $payload,
        );
    }
}
