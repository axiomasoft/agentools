<?php

declare(strict_types=1);

namespace AgenTools\Tests\Idempotency;

use AgenTools\Contracts\ExternalEffectProbe;
use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\IdempotencyCoordinator;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\TestKit\InMemoryDeduplicationStore;
use AgenTools\Idempotency\StoredToolResult;
use AgenTools\Idempotency\ToolIdempotencyCapability;
use AgenTools\Idempotency\ToolIdempotencyCatalog;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class IdempotencyCoordinatorTest extends TestCase
{
    public function testUnknownHighRiskDeniesBeforeInsert(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $calls = 0;
        $coordinator = $this->coordinator($store, new ToolIdempotencyCatalog([], ['pay' => 'write']), function () use (&$calls): ToolExecutionResult {
            $calls++;
            return ToolExecutionResult::success(['ok' => true]);
        });
        $result = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.idempotency_capability_unknown', $result->error?->code);
        self::assertSame(0, $calls);
        $later = $this->coordinator($store, new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::NativeKey], ['pay' => 'write']), function () use (&$calls): ToolExecutionResult {
            $calls++;
            return ToolExecutionResult::success(['ok' => true]);
        })->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertFalse($later->isError());
        self::assertSame(1, $calls);
    }

    public function testNativeKeyForwardsTheDurableKey(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $seen = [];
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::NativeKey], ['pay' => 'write']),
            function (string $tool, array $arguments, string $key) use (&$seen): ToolExecutionResult {
                $seen[] = $key;
                return ToolExecutionResult::success(['ok' => true]);
            },
        );
        $first = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertFalse($first->isError());
        self::assertSame(['at1:abcdefgh'], $seen);
    }

    public function testAtMostOnceTakeoverStoresAmbiguousWithoutInvoke(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $this->plantInFlight($store, $now);
        $calls = 0;
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::AtMostOnceOnly], ['pay' => 'write']),
            function () use (&$calls): ToolExecutionResult {
                $calls++;
                return ToolExecutionResult::success(['ok' => true]);
            },
            $clock,
        );
        $now = $now->add(new \DateInterval('PT31S'));
        $second = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertTrue($second->isError());
        self::assertSame('agentools.precondition.idempotency_ambiguous_effect', $second->error?->code);
        self::assertSame(0, $calls);
        $replay = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertTrue($replay->isError());
        self::assertSame('agentools.precondition.idempotency_ambiguous_effect', $replay->error?->code);
        self::assertSame(0, $calls);
    }

    public function testReadBeforeWriteMissingProbeIsAmbiguousOnTakeover(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $this->plantInFlight($store, $now);
        $calls = 0;
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::ReadBeforeWrite], ['pay' => 'write']),
            function () use (&$calls): ToolExecutionResult {
                $calls++;
                return ToolExecutionResult::success(['ok' => true]);
            },
            $clock,
        );
        $now = $now->add(new \DateInterval('PT31S'));
        $second = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertTrue($second->isError());
        self::assertSame('agentools.precondition.idempotency_ambiguous_effect', $second->error?->code);
        self::assertSame(0, $calls);
    }

    public function testReadBeforeWriteProbeReplaysStoredResult(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $this->plantInFlight($store, $now);
        $calls = 0;
        $probe = new class implements ExternalEffectProbe {
            public function find(string $tool, string $idempotencyKey): StoredToolResult
            {
                return new StoredToolResult(value: ['probed' => true]);
            }
        };
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::ReadBeforeWrite], ['pay' => 'write']),
            function () use (&$calls): ToolExecutionResult {
                $calls++;
                return ToolExecutionResult::success(['ok' => true]);
            },
            $clock,
            $probe,
        );
        $now = $now->add(new \DateInterval('PT31S'));
        $second = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertFalse($second->isError());
        self::assertSame(['probed' => true], $second->value);
        self::assertSame(0, $calls);
    }

    public function testNativeKeyTakeoverReinvokesTheTool(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $this->plantInFlight($store, $now);
        $calls = 0;
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::NativeKey], ['pay' => 'write']),
            function () use (&$calls): ToolExecutionResult {
                $calls++;
                return ToolExecutionResult::success(['ok' => true]);
            },
            $clock,
        );
        $now = $now->add(new \DateInterval('PT31S'));
        $second = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertFalse($second->isError());
        self::assertSame(1, $calls);
    }

    /**
     * @param callable(string, array<string, mixed>, string): ToolExecutionResult $handler
     */
    private function coordinator(
        InMemoryDeduplicationStore $store,
        ToolIdempotencyCatalog $catalog,
        callable $handler,
        ?\Closure $clock = null,
        ?ExternalEffectProbe $probe = null,
    ): IdempotencyCoordinator {
        $port = new class ($handler) implements ToolPort {
            /** @param callable(string, array<string, mixed>, string): ToolExecutionResult $handler */
            public function __construct(private readonly mixed $handler)
            {
            }

            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
            {
                return ($this->handler)($tool, $arguments, $idempotencyKey);
            }
        };

        return new IdempotencyCoordinator(
            $store,
            $catalog,
            $port,
            new Canonicalizer(),
            'tenant-1',
            'action-1',
            clock: $clock,
            probe: $probe,
        );
    }

    private function plantInFlight(InMemoryDeduplicationStore $store, \DateTimeImmutable $now): void
    {
        $canonicalizer = new Canonicalizer();
        $store->acquire(
            new IdempotencyRequest(
                new IdempotencyScope('tenant-1', 'pay', new IdempotencyKey('at1:abcdefgh')),
                ArgumentsFingerprint::fromArguments(['amount' => 1], $canonicalizer),
                'action-1',
            ),
            $now,
            new \DateInterval('PT30S'),
        );
    }

    private function policy(): DeduplicationPolicy
    {
        return new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
    }
}
