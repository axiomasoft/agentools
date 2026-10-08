<?php

declare(strict_types=1);

namespace AgenTools\Tests\Adversarial;

use AgenTools\Action\{ActionEvent, ActionJournal, ActionReducer, ActionSnapshot, Actor, JournalHashChain, LifecycleException};
use AgenTools\Contracts\ToolPort;
use AgenTools\Effects\{EffectApplier, EffectCommitter};
use AgenTools\Error\{EventStoreFailure, ToolError, ToolExecutionResult};
use AgenTools\Idempotency\{DeduplicationPolicy, IdempotencyCoordinator, IdempotencyKey, ToolIdempotencyCapability, ToolIdempotencyCatalog};
use AgenTools\Ops\{AuditRedaction, JournalAppender};
use AgenTools\Persistence\{PdoPostgresEventStore, UncommittedEvent};
use AgenTools\Scenario\{ScenarioOutcome, ScenarioRunner};
use AgenTools\Schema\Canonicalizer;
use AgenTools\TestKit\{FixedClock, InMemoryDeduplicationStore, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

final class AuditRegressionTest extends TestCase
{
    /** @return array{audit_invalid: list<array<string, mixed>>, audit_valid: list<array<string, mixed>>, jcs: list<array{raw: string, canonical: string}>, scenario: array<string, mixed>, scenario_keys: list<string>, timestamps: list<string>} */
    private function fixtures(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/audit-regressions-v1.json');
        self::assertIsString($raw);
        /** @var array{audit_invalid: list<array<string, mixed>>, audit_valid: list<array<string, mixed>>, jcs: list<array{raw: string, canonical: string}>, scenario: array<string, mixed>, scenario_keys: list<string>, timestamps: list<string>} $fixture */
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $fixture;
    }

    /** @param array<string, mixed> $payload */
    private function event(string $id, string $kind = 'audit.policy_evaluated', array $payload = [], string $ts = '2026-10-08T10:00:00Z'): UncommittedEvent
    {
        return new UncommittedEvent($id, $kind, 1, '2026-09', new Actor('user', 'u'), $ts, $payload);
    }

    public function testAuditValidationRefusesNestedDataBeforePersistenceAndExport(): void
    {
        foreach ($this->fixtures()['audit_invalid'] as $i => $payload) {
            $store = new InMemoryEventStore();
            try {
                $store->append('tenant', 'action', 0, [$this->event('audit-' . $i, payload: $payload)]);
                self::fail('Invalid audit payload was persisted: ' . $i);
            } catch (EventStoreFailure) {
                self::assertSame([], $store->load('tenant', 'action')->events);
            }
            try {
                (new AuditRedaction())->redactDocument(['kind' => 'audit.policy_evaluated', 'payload' => $payload]);
                self::fail('Invalid audit payload reached export: ' . $i);
            } catch (EventStoreFailure $failure) {
                self::assertSame('agentools.validation.audit_forbidden_field', $failure->error->code);
            }
        }
        foreach ($this->fixtures()['audit_valid'] as $payload) {
            $store = new InMemoryEventStore();
            $store->append('tenant', 'action', 0, [$this->event('valid-audit', payload: $payload)]);
            self::assertCount(1, $store->load('tenant', 'action')->events);
        }
    }

    public function testJcsMatchesIndependentEcmascriptNumbers(): void
    {
        foreach ($this->fixtures()['jcs'] as $case) {
            self::assertSame($case['canonical'], (new Canonicalizer())->canonicalizeJson($case['raw']));
        }
    }

    public function testInvalidLifecycleIsNeverCommittedAndCurrentRevisionWorks(): void
    {
        $store = new InMemoryEventStore();
        $writer = new JournalAppender($store, 'tenant', 'action', new Actor('user', 'u'), new FixedClock(new \DateTimeImmutable('2026-10-08T10:00:00Z')));
        try {
            $writer->append('action_completed');
            self::fail('Completion without creation was accepted');
        } catch (LifecycleException) {
            self::assertSame([], $store->load('tenant', 'action')->events);
        }
        $writer->append('action_created')->append('action_started')->append('epoch_started');
        $journal = $writer->journal();
        self::assertSame('2026-09', $journal->events()[0]->specRevision);
        $applier = new class implements EffectApplier {
            public int $calls = 0;
            public function apply(\AgenTools\Action\EffectRecord $effect): void { $this->calls++; }
        };
        $request = new ActionEvent('effect-request', 'action', 4, 3, '2026-10-08T10:00:00Z', new Actor('user', 'u'), 'effect_requested', 1, '2026-09', ['effect_id' => 'effect'], 1);
        $next = (new EffectCommitter())->commit($journal, $request, $applier);
        self::assertSame(4, $next->state()->seq);
        self::assertSame(1, $applier->calls);
        $snapshot = new ActionSnapshot((new ActionJournal([$journal->events()[0]]))->state(), '2099-01', 'trusted-hash');
        self::assertSame(3, ActionSnapshot::restoreOrDiscard($snapshot, 'trusted-hash', $journal, [])->seq);
        $snapshot = new ActionSnapshot($snapshot->state, '2026-09', 'trusted-hash');
        self::assertSame(3, ActionSnapshot::restoreOrDiscard($snapshot, 'trusted-hash', $journal, [])->seq);
    }

    public function testScenarioKeysComposeAndCompensationUsesOriginalContext(): void
    {
        $tools = new class implements ToolPort {
            /** @var list<array{string, array<string, mixed>, string}> */
            public array $calls = [];
            public function clear(): void { $this->calls = []; }
            public bool $throwUndo = false;
            public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult {
                new IdempotencyKey($idempotencyKey);
                $this->calls[] = [$tool, $arguments, $idempotencyKey];
                if ($tool === 'undo' && $this->throwUndo) throw new \RuntimeException('transport failed');
                return $tool === 'fail' ? ToolExecutionResult::failure(new ToolError('test.validation.failed', 'validation', false, 'Synthetic failure')) : ToolExecutionResult::success([]);
            }
        };
        $runner = new ScenarioRunner();
        $scenario = $this->fixtures()['scenario'];
        $result = $runner->run($scenario, ['resource' => 'resource-1'], $tools, 'persisted-run-1');
        self::assertSame(ScenarioOutcome::Compensated, $result->outcome);
        self::assertSame(['resource' => 'resource-1'], $tools->calls[2][1]);
        $firstKeys = array_column($tools->calls, 2);
        self::assertSame($this->fixtures()['scenario_keys'], $firstKeys);
        $tools->clear();
        $runner->run($scenario, ['resource' => 'resource-1'], $tools, 'persisted-run-1');
        self::assertSame($firstKeys, array_column($tools->calls, 2));
        $tools->clear();
        $runner->run($scenario, ['resource' => 'resource-1'], $tools, 'different-run');
        self::assertNotSame($firstKeys[0], $tools->calls[0][2]);
        self::assertSame(ScenarioOutcome::CompensationFailed, $runner->run($scenario, [], $tools)->outcome);
        $tools->throwUndo = true;
        self::assertSame(ScenarioOutcome::CompensationFailed, $runner->run($scenario, ['resource' => 'resource-1'], $tools)->outcome);
        $policy = new DeduplicationPolicy(new \DateInterval('PT2S'), new \DateInterval('PT1S'), new \DateInterval('PT1S'));
        $coordinator = new IdempotencyCoordinator(new InMemoryDeduplicationStore($policy), new ToolIdempotencyCatalog(['reserve' => ToolIdempotencyCapability::NativeKey]), $tools, new Canonicalizer(), 'tenant', 'action');
        $scenario['steps'] = [['id' => 'reserve', 'tool' => 'reserve']];
        $tools->clear();
        $first = $runner->run($scenario, [], $coordinator, 'resume-identity');
        $second = $runner->run($scenario, [], $coordinator, 'resume-identity');
        self::assertSame([ScenarioOutcome::Completed, ScenarioOutcome::Completed], [$first->outcome, $second->outcome]);
        self::assertCount(1, $tools->calls);
    }

    public function testExpiredCompletedIdempotencyKeyMatchesMemoryAndPostgres(): void
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN');
        if (!$dsn) $this->markTestSkipped('Explicit isolated PostgreSQL DSN required.');
        $pdo = new \PDO($dsn, getenv('AGENTOOLS_TEST_PG_USER') ?: 'agentools', getenv('AGENTOOLS_TEST_PG_PASSWORD') ?: 'agentools-test');
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/idempotency-lease.v1.sql');
        self::assertIsString($sql);
        $pdo->exec($sql);
        $policy = new DeduplicationPolicy(new \DateInterval('PT2S'), new \DateInterval('PT1S'), new \DateInterval('PT1S'));
        $now = new \DateTimeImmutable('2026-10-08T10:00:00Z');
        $scope = new \AgenTools\Idempotency\IdempotencyScope('tenant', 'reserve', new IdempotencyKey('at1:expiry-' . bin2hex(random_bytes(8))));
        $request = new \AgenTools\Idempotency\IdempotencyRequest($scope, \AgenTools\Idempotency\ArgumentsFingerprint::fromJcs('{"v":1}'), 'action');
        $changed = new \AgenTools\Idempotency\IdempotencyRequest($scope, \AgenTools\Idempotency\ArgumentsFingerprint::fromJcs('{"v":2}'), 'action');
        foreach ([new InMemoryDeduplicationStore($policy), new \AgenTools\Idempotency\PdoPostgresIdempotencyStore($pdo, $policy)] as $store) {
            $lease = $store->acquire($request, $now, new \DateInterval('PT30S'))->lease;
            self::assertNotNull($lease);
            $store->complete($lease, new \AgenTools\Idempotency\StoredToolResult(value: ['ok' => true]), \AgenTools\Idempotency\StoredResultDigest::fromJcs('{"ok":true}'), $now);
            try {
                $store->acquire($changed, $now, new \DateInterval('PT30S'));
                self::fail('Unexpired completed key must reject changed arguments.');
            } catch (\AgenTools\Idempotency\IdempotencyFailure $failure) {
                self::assertSame('agentools.validation.idempotency_key_reuse', $failure->error->code);
            }
            self::assertSame(\AgenTools\Idempotency\DeduplicationState::New, $store->acquire($changed, $now->modify('+2 seconds'), new \DateInterval('PT30S'))->state);
            try {
                $store->acquire($request, $now->modify('+10 seconds'), new \DateInterval('PT30S'));
                self::fail('Expired retention must not clear a live in-progress lease.');
            } catch (\AgenTools\Idempotency\IdempotencyFailure $failure) {
                self::assertSame('agentools.validation.idempotency_key_reuse', $failure->error->code);
            }
        }
    }

    public function testPostgresPreservesTimestampBytesAndChainAcrossConnections(): void
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN');
        if (!$dsn) $this->markTestSkipped('Explicit isolated PostgreSQL DSN required.');
        $pdo = new \PDO($dsn, getenv('AGENTOOLS_TEST_PG_USER') ?: 'agentools', getenv('AGENTOOLS_TEST_PG_PASSWORD') ?: 'agentools-test');
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/event-store.v1.sql');
        self::assertIsString($sql);
        $pdo->exec($sql);
        $store = new PdoPostgresEventStore($pdo);
        foreach ($this->fixtures()['timestamps'] as $timestamp) {
            $action = 'roundtrip-' . bin2hex(random_bytes(8));
            $pdo->exec("SET TIME ZONE 'Europe/Moscow'");
            $batch = [$this->event(bin2hex(random_bytes(16)), 'action_created', ts: $timestamp), $this->event(bin2hex(random_bytes(16)), 'action_started', ts: $timestamp)];
            $store->append('tenant', $action, 0, $batch);
            $reader = new \PDO($dsn, getenv('AGENTOOLS_TEST_PG_USER') ?: 'agentools', getenv('AGENTOOLS_TEST_PG_PASSWORD') ?: 'agentools-test');
            $reader->exec("SET TIME ZONE 'America/New_York'");
            $readStore = new PdoPostgresEventStore($reader);
            $loaded = $readStore->load('tenant', $action)->events;
            self::assertSame($timestamp, $loaded[0]->timestamp);
            $chain = new JournalHashChain(new Canonicalizer());
            self::assertSame(2, (new ActionReducer('2026-09', $chain))->fold($loaded)->seq);
            $store->append('tenant', $action, 2, [$this->event(bin2hex(random_bytes(16)), 'action_completed', ts: $timestamp)]);
            self::assertSame(3, (new ActionReducer('2026-09', $chain))->fold($readStore->load('tenant', $action)->events)->seq);
            self::assertSame('idempotent_replay', $store->append('tenant', $action, 0, $batch)->outcome);
        }
    }
}
