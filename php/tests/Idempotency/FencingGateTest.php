<?php

declare(strict_types=1);

namespace AgenTools\Tests\Idempotency;

use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\IdempotencyCoordinator;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\TestKit\InMemoryDeduplicationStore;
use AgenTools\Idempotency\PdoPostgresIdempotencyStore;
use AgenTools\Idempotency\ToolIdempotencyCapability;
use AgenTools\Idempotency\ToolIdempotencyCatalog;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FencingGateTest extends TestCase
{
    public function testSupportedInvokePathIsFenced(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(IdempotencyStore::class))->getMethods(),
        );
        self::assertNotContains('resolve', $methods);
        $complete = (new ReflectionClass(IdempotencyStore::class))->getMethod('complete');
        $type = $complete->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(IdempotencyLease::class, $type->getName());

        $slice = file_get_contents(dirname(__DIR__, 2) . '/reference/Scenario/SliceToolCoordinator.php');
        self::assertIsString($slice);
        self::assertStringContainsString('$this->idempotency->call(', $slice);
        self::assertStringNotContainsString('$this->tools->call(', $slice);
        self::assertStringNotContainsString('complete($request', $slice);
    }

    public function testFiftyCoordinatorAcquiresYieldOneInvoke(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy());
        $holder = new class {
            public ?IdempotencyCoordinator $coordinator = null;
            public int $calls = 0;
            public int $inFlight = 0;
        };
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::NativeKey], ['pay' => 'write']),
            function () use ($holder): ToolExecutionResult {
                $holder->calls++;
                $subject = $holder->coordinator;
                self::assertInstanceOf(IdempotencyCoordinator::class, $subject);
                for ($i = 0; $i < 49; $i++) {
                    $nested = $subject->call('pay', ['amount' => 1], 'at1:abcdefgh');
                    if ($nested->error?->code === 'agentools.conflict.idempotency_in_flight') {
                        $holder->inFlight++;
                    }
                }
                return ToolExecutionResult::success(['ok' => true]);
            },
        );
        $holder->coordinator = $coordinator;
        $result = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertFalse($result->isError());
        self::assertSame(1, $holder->calls);
        self::assertSame(49, $holder->inFlight);
    }

    public function testPostgresTakeoverDoesNotLetStaleWorkerComplete(): void
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        try {
            $pdo = new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            $this->markTestSkipped('PostgreSQL test database is not available.');
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/idempotency-lease.v1.sql');
        self::assertIsString($sql);
        $pdo->exec($sql);
        $pdo->exec('TRUNCATE agentools_idempotency_leases');
        $store = new PdoPostgresIdempotencyStore($pdo, $this->policy());
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $canonicalizer = new Canonicalizer();
        $request = new IdempotencyRequest(
            new IdempotencyScope('tenant-1', 'pay', new IdempotencyKey('at1:abcdefgh')),
            ArgumentsFingerprint::fromArguments(['amount' => 1], $canonicalizer),
            'action-1',
        );
        $first = $store->acquire($request, $now, new \DateInterval('PT30S'));
        self::assertNotNull($first->lease);
        $later = $now->add(new \DateInterval('PT31S'));
        $calls = 0;
        $clock = static function () use ($later): \DateTimeImmutable {
            return $later;
        };
        $coordinator = $this->coordinator(
            $store,
            new ToolIdempotencyCatalog(['pay' => ToolIdempotencyCapability::AtMostOnceOnly], ['pay' => 'write']),
            function () use (&$calls): ToolExecutionResult {
                $calls++;
                return ToolExecutionResult::success(['ok' => true]);
            },
            $clock,
        );
        $result = $coordinator->call('pay', ['amount' => 1], 'at1:abcdefgh');
        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.idempotency_ambiguous_effect', $result->error?->code);
        self::assertSame(0, $calls);
        try {
            $store->complete(
                $first->lease,
                new \AgenTools\Idempotency\StoredToolResult(value: ['stale' => true]),
                \AgenTools\Idempotency\StoredResultDigest::fromJcs('{"stale":true}'),
                $later,
            );
            self::fail('stale worker must not complete after takeover');
        } catch (\AgenTools\Idempotency\IdempotencyFailure $failure) {
            self::assertSame('agentools.conflict.idempotency_lost_lease', $failure->error->code);
        }
    }

    /**
     * @param callable(string, array<string, mixed>, string): ToolExecutionResult $handler
     */
    private function coordinator(
        IdempotencyStore $store,
        ToolIdempotencyCatalog $catalog,
        callable $handler,
        ?\Closure $clock = null,
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
        );
    }

    private function policy(): DeduplicationPolicy
    {
        return new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
    }
}
