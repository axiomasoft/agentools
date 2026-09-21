<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Action\Actor;
use AgenTools\Contracts\EventLog;
use AgenTools\Contracts\EventStore;
use AgenTools\Persistence\AppendResult;
use AgenTools\TestKit\InMemoryEventStore;
use AgenTools\Persistence\UncommittedEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InMemoryEventStoreTest extends TestCase
{
    public function testProductionInterfaceHasNoCallerSeqAppend(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(EventStore::class))->getMethods(),
        );
        self::assertSame(['append', 'load'], $methods);
        $append = (new ReflectionClass(EventStore::class))->getMethod('append');
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $append->getParameters(),
        );
        self::assertNotContains(EventLog::class, $types);
    }

    public function testEmptyStreamStartsAtSeqOne(): void
    {
        $store = new InMemoryEventStore();
        $result = $store->append('tenant-a', 'action-1', 0, [$this->event('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')]);
        self::assertSame(1, $result->firstSeq);
        self::assertSame(1, $result->lastSeq);
        self::assertSame(AppendResult::APPENDED, $result->outcome);
        $page = $store->load('tenant-a', 'action-1');
        self::assertCount(1, $page->events);
        self::assertSame(1, $page->events[0]->seq);
        self::assertSame(0, $page->events[0]->expectedPrevSeq);
        self::assertFalse($page->hasMore);
    }

    public function testDefaultPageIsFiveHundred(): void
    {
        $store = new InMemoryEventStore();
        $batch = [];
        for ($i = 0; $i < 64; $i++) {
            $batch[] = $this->event(sprintf('%032d', $i));
        }
        $store->append('tenant-a', 'action-1', 0, $batch);
        $page = $store->load('tenant-a', 'action-1');
        self::assertCount(64, $page->events);
        self::assertSame(InMemoryEventStore::DEFAULT_PAGE, 500);
    }

    public function testTenantIsolation(): void
    {
        $store = new InMemoryEventStore();
        $event = $this->event('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $store->append('tenant-a', 'action-1', 0, [$event]);
        $store->append('tenant-b', 'action-1', 0, [$event]);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
        self::assertCount(1, $store->load('tenant-b', 'action-1')->events);
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
