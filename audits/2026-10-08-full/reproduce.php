<?php
declare(strict_types=1);

// Diagnostic probes, not replacement acceptance tests. All secrets are synthetic.
require dirname(__DIR__, 2) . '/php/vendor/autoload.php';

use AgenTools\Action\{ActionEvent, ActionJournal, ActionReducer, ActionSnapshot, Actor, JournalHashChain};
use AgenTools\Confirmation\{KeyId, SigningKey, TokenEnvelope};
use AgenTools\Contracts\ToolPort;
use AgenTools\Effects\{EffectApplier, EffectCommitter};
use AgenTools\Error\{ToolError, ToolExecutionResult};
use AgenTools\Idempotency\{ArgumentsFingerprint, DeduplicationPolicy, IdempotencyKey, IdempotencyRequest, IdempotencyScope, PdoPostgresIdempotencyStore, StoredResultDigest, StoredToolResult};
use AgenTools\Ops\{AuditRedaction, JournalAppender};
use AgenTools\Persistence\{EventStoreSupport, PdoPostgresEventStore, UncommittedEvent};
use AgenTools\Scenario\ScenarioRunner;
use AgenTools\Schema\Canonicalizer;
use AgenTools\TestKit\{FixedClock, InMemoryDeduplicationStore, InMemoryEventStore, StaticTokenKeyProvider};

function probe(string $name, Closure $fn): void {
    try { $result = $fn(); }
    catch (Throwable $e) { $result = ['exception' => $e::class, 'message' => $e->getMessage()]; }
    echo json_encode(['probe' => $name, 'actual' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}
function event(string $id, string $kind, int $seq, string $rev = '2026-09', array $payload = [], ?int $epoch = null): ActionEvent {
    return new ActionEvent($id, 'audit-action', $seq, $seq - 1, '2026-10-08T10:00:00+00:00', new Actor('user', 'u'), $kind, 1, $rev, $payload, $epoch);
}
probe('jcs_large_integer', fn() => (new Canonicalizer())->canonicalizeJson('{"n":9007199254740993}'));

probe('audit_nested_fields', function () {
    $payload = ['trace' => ['token' => 'AUDIT_FAKE_SECRET', 'email' => 'audit@example.invalid']];
    $clean = (new AuditRedaction())->redactDocument(['payload' => $payload]);
    $uncommitted = new UncommittedEvent('audit-nested', 'audit.policy_evaluated', 1, '2026-09', new Actor('user', 'u'), '2026-10-08T10:00:00Z', $payload);
    (new EventStoreSupport())->assertAppendable('tenant', 'audit-action', [$uncommitted]);
    return ['redacted' => $clean, 'store_validation' => 'accepted'];
});
probe('snapshot_revision_mismatch', function () {
    $reducer = new ActionReducer('2026-09');
    $prefix = new ActionJournal([event('s1', 'action_created', 1)]);
    $journal = new ActionJournal([...$prefix->events(), event('s2', 'action_started', 2)]);
    $snapshot = new ActionSnapshot($prefix->state($reducer), '2099-01', 'synthetic-expected-hash');
    $restored = ActionSnapshot::restoreOrDiscard($snapshot, 'synthetic-expected-hash', $journal, [], $reducer);
    return ['snapshot_revision' => $snapshot->specRevision, 'expected_revision' => '2026-09', 'accepted_seq' => $restored->seq, 'full_journal_seq' => $journal->state($reducer)->seq];
});
probe('candidate_effect_committer', function () {
    $journal = new ActionJournal([event('c1', 'action_created', 1), event('c2', 'action_started', 2), event('c3', 'epoch_started', 3, epoch: 1)]);
    $applier = new class implements EffectApplier { public function apply(\AgenTools\Action\EffectRecord $effect): void {} };
    (new EffectCommitter())->commit($journal, event('c4', 'effect_requested', 4, payload: ['effect_id' => 'fx'], epoch: 1), $applier);
    return 'accepted';
});
probe('scenario_compensation_context', function () {
    $tools = new class implements ToolPort {
        public array $calls = [];
        public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult {
            $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];
            return $tool === 'fail' ? ToolExecutionResult::failure(new ToolError('audit.validation.failed', 'validation', false, 'Synthetic failure')) : ToolExecutionResult::success([]);
        }
    };
    $scenario = ['spec_rev' => '2026-09', 'scenario_id' => 'audit', 'steps' => [
        ['id' => 'first', 'tool' => 'reserve', 'compensate' => ['tool' => 'undo', 'input_map' => ['resource' => '$ctx.resource']]],
        ['id' => 'second', 'tool' => 'fail'],
    ]];
    try { $out = (new ScenarioRunner())->run($scenario, ['resource' => 'resource-1'], $tools); $status = $out->outcome->value; }
    catch (Throwable $e) { $status = $e::class . ': ' . $e->getMessage(); }
    return ['outcome' => $status, 'calls' => $tools->calls];
});
probe('journal_invalid_transition_persisted', function () {
    $store = new InMemoryEventStore();
    $appender = new JournalAppender($store, 'tenant', 'audit-action', new Actor('user', 'u'), new FixedClock(new DateTimeImmutable('2026-10-08T10:00:00Z')));
    try { $appender->append('action_completed'); $error = null; }
    catch (Throwable $e) { $error = $e::class; }
    return ['exception' => $error, 'stored_events' => count($store->load('tenant', 'audit-action')->events)];
});
probe('scenario_idempotency_composition', function () {
    $tools = new class implements ToolPort {
        public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult { return ToolExecutionResult::success([]); }
    };
    $policy = new DeduplicationPolicy(new DateInterval('PT2S'), new DateInterval('PT1S'), new DateInterval('PT1S'));
    $catalog = new \AgenTools\Idempotency\ToolIdempotencyCatalog(['reserve' => \AgenTools\Idempotency\ToolIdempotencyCapability::NativeKey]);
    $coordinator = new \AgenTools\Idempotency\IdempotencyCoordinator(new InMemoryDeduplicationStore($policy), $catalog, $tools, new Canonicalizer(), 'tenant', 'audit-action');
    (new ScenarioRunner())->run(['spec_rev' => '2026-09', 'scenario_id' => 'audit', 'steps' => [['id' => 'first', 'tool' => 'reserve']]], [], $coordinator);
    return 'accepted';
});

$claims = ['action_id' => 'a', 'alg' => 'HS256', 'args_fingerprint' => 'fp', 'aud' => 'confirmation', 'descriptor_digest' => 'dd', 'expires_at' => '2026-02-30T12:00:00+00:00', 'iat' => '2026-02-30T10:00:00+00:00', 'iss' => 'agentools', 'jti' => 'audit-jti', 'kid' => 'audit-key', 'nbf' => '2026-02-30T10:00:00+00:00', 'principal' => 'u', 'proposal_id' => 'p', 'proposal_version' => 1, 'purpose' => 'confirmation', 'tenant_id' => 'tenant', 'tool_name' => 'tool', 'v' => 1];
probe('invalid_calendar_claim', function () use ($claims) {
    $key = new SigningKey(new KeyId('audit-key'), 'HS256', str_repeat('k', 32));
    // Sign raw bytes so PHP and Dart receive the exact same signed malformed input.
    $raw = TokenEnvelope::canonicalJson($claims);
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $token = 'at1.' . $b64($raw) . '.' . $b64(hash_hmac('sha256', $raw, $key->secret, true));
    TokenEnvelope::parse($token, new StaticTokenKeyProvider($key), new DateTimeImmutable('2026-03-02T11:00:00Z'), 'agentools', 'confirmation');
    return 'accepted';
});

// Deliberately require a dedicated audit database; never fall back to a default DSN.
$dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: '';
if (!preg_match('/^pgsql:host=127\.0\.0\.1;port=\d+;dbname=agentools_audit_test$/D', $dsn)) {
    echo "{\"database_probes\":\"skipped: dedicated audit DSN required\"}\n";
    exit(0);
}
$pdo = new PDO($dsn, 'agentools', 'agentools-test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE SCHEMA IF NOT EXISTS audit_probes');
$pdo->exec('SET search_path TO audit_probes');
foreach (['event-store', 'idempotency-lease'] as $schema) {
    $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/fixtures/hardening/sql/' . $schema . '.v1.sql'));
}
probe('postgres_hash_chain_roundtrip', function () use ($pdo) {
    $store = new PdoPostgresEventStore($pdo);
    $action = 'roundtrip-' . bin2hex(random_bytes(4));
    $make = fn(string $id, string $kind) => new UncommittedEvent($id, $kind, 1, '2026-09', new Actor('user', 'u'), '2026-10-08T10:00:00.123Z', []);
    $store->append('tenant', $action, 0, [$make(bin2hex(random_bytes(16)), 'action_created'), $make(bin2hex(random_bytes(16)), 'action_started')]);
    $events = $store->load('tenant', $action)->events;
    $chain = new JournalHashChain(new Canonicalizer());
    try { (new ActionReducer('2026-09', $chain))->fold($events); $fold = 'accepted'; }
    catch (Throwable $e) { $fold = $e::class; }
    return ['timestamp_loaded' => $events[0]->timestamp, 'chain_matches' => $events[1]->previousHash === $chain->hash($events[0]), 'fold' => $fold];
});
probe('expired_idempotency_key_parity', function () use ($pdo) {
    $policy = new DeduplicationPolicy(new DateInterval('PT2S'), new DateInterval('PT1S'), new DateInterval('PT1S'));
    $now = new DateTimeImmutable('2026-10-08T10:00:00Z');
    $scope = new IdempotencyScope('tenant', 'tool', new IdempotencyKey('at1:expiry-' . bin2hex(random_bytes(4))));
    $first = new IdempotencyRequest($scope, ArgumentsFingerprint::fromArguments(['v' => 1], new Canonicalizer()), 'a');
    $second = new IdempotencyRequest($scope, ArgumentsFingerprint::fromArguments(['v' => 2], new Canonicalizer()), 'b');
    $out = [];
    foreach (['memory' => new InMemoryDeduplicationStore($policy), 'postgres' => new PdoPostgresIdempotencyStore($pdo, $policy)] as $name => $store) {
        $lease = $store->acquire($first, $now, new DateInterval('PT30S'))->lease;
        $store->complete($lease, new StoredToolResult(value: ['ok' => true]), StoredResultDigest::fromJcs('{"ok":true}'), $now);
        try { $out[$name] = $store->acquire($second, $now->modify('+3 seconds'), new DateInterval('PT30S'))->state->value; }
        catch (Throwable $e) { $out[$name] = $e::class . ': ' . $e->getMessage(); }
    }
    return $out;
});
