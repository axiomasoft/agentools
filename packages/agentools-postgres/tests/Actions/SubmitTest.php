<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Actions;

use AgenTools\Action\{ActionReducer, Actor, JournalHashChain};
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionRequest, ActionSubmission, CancelRequest, ReconcileRequest};
use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode, IntegrityConflict, Outcome, ProposalId, Ref, TenantId};
use AgenToolsPlatform\Examples\DirectAction\{Composition, Host, Jcs};
use AgenToolsPlatform\Postgres\Actions\Projection;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\StorageFailure;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubmitTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Composition $direct;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->direct = $this->composition();
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
            $this->database->dropSchema($this->schema);
        }
    }

    private function composition(string $tenant = 'tenant-a', string $actor = 'actor', ?PDO $pdo = null): Composition
    {
        $pdo ??= $this->database->pdo;
        return new Composition($pdo, $this->schema, new Host($pdo, new TenantId($tenant), $actor));
    }

    private function countRows(string $table): int
    {
        $query = $this->database->pdo->query('SELECT count(*) FROM "' . $this->schema . '"."' . $table . '"');
        self::assertNotFalse($query);
        return (int) $query->fetchColumn();
    }

    /** @param class-string<\Throwable> $type @param callable():mixed $operation */
    private function rejects(callable $operation, string $type = ContractViolation::class, ?ErrorCode $code = null): void
    {
        try { $operation(); self::fail('Expected rejection'); }
        catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
            if ($code !== null) { self::assertInstanceOf(ContractViolation::class, $error); self::assertSame($code, $error->errorCode); }
        }
    }

    public function testReplayAndConflict(): void
    {
        $d = $this->direct;
        $submission = $d->submission();
        $first = $d->application->submit($submission)->view;
        self::assertSame('created', $first->receipt->wireStatus);
        self::assertNull($first->outcome());
        self::assertSame($first->receipt->toArray(), $d->application->submit($submission)->view->receipt->toArray());
        $this->rejects(fn () => $d->application->submit($d->submission(input: '{"query":"changed"}')), IntegrityConflict::class);
        self::assertSame(1, $this->countRows('agentools_events'));
        self::assertSame(1, $this->countRows('action_submissions'));
        self::assertSame(1, $this->countRows('outbox'));
        self::assertSame(0, $this->countRows('effect_intents'));
        $this->append($first->receipt->actionId, ['action_started', 'action_completed']);
        self::assertSame('completed', $d->application->status($first->receipt->actionId, $d->context())->receipt->wireStatus);
        self::assertSame($first->receipt->toArray(), $d->application->submit($submission)->view->receipt->toArray());
        $request = $submission->request;
        $changed = new ActionSubmission(new ActionRequest($request->definition, new Ref($d->host->tenant, 'changed-origin'), null,
            $request->idempotencyKey, $request->inputRef, $request->inputHash, $request->context, $request->deadline, $d->hasher),
            $submission->inputJson, $d->hasher, new Jcs());
        $this->rejects(fn () => $d->application->submit($changed), IntegrityConflict::class);
    }

    public function testStatusScope(): void
    {
        $d = $this->direct; $saved = $d->application->submit($d->submission())->view;
        $id = $saved->receipt->actionId;
        $this->rejects(fn () => $this->composition(actor: 'other')->application->status($id, $this->composition(actor: 'other')->context()),
            code: ErrorCode::AccessDenied);
        $foreign = $this->composition('tenant-b');
        $this->rejects(fn () => $foreign->application->status(new ActionId($foreign->host->tenant, $id->value), $foreign->context()), code: ErrorCode::AccessDenied);
        $this->rejects(fn () => $d->application->status($id, $d->context('other-session')), code: ErrorCode::AccessDenied);
        $d->host->revision = 'policy-2';
        $this->rejects(fn () => $d->application->status($id, $d->context()), code: ErrorCode::AccessDenied);
        $d->host->revision = 'policy-1';
        $view = $d->application->status($id, $d->context());
        self::assertNull($view->resultRef);
        $json = json_encode($view, JSON_THROW_ON_ERROR);
        foreach (['query', 'hello', 'input_ref', 'owner_operation_key', 'execution_context'] as $secret) { self::assertStringNotContainsString($secret, $json); }
        self::assertSame(1, $this->countRows('agentools_events'));
    }

    public function testCurrentGuardsAndInvalidInput(): void
    {
        $d = $this->direct; $submission = $d->submission();
        $this->rejects(fn () => $d->application->submit($d->submission(input: '{"query":42}')), code: ErrorCode::InvalidInput);
        $this->rejects(fn () => $d->application->submit($d->submission(deadline: '2000-01-01T00:00:00Z')), code: ErrorCode::DeadlineExceeded);
        self::assertSame(0, $this->countRows('action_origins'));
        $id = $d->application->submit($submission)->view->receipt->actionId;
        $d->host->denied = true;
        $this->rejects(fn () => $d->application->submit($submission), code: ErrorCode::AccessDenied);
        $this->rejects(fn () => $d->application->status($id, $submission->request->context), code: ErrorCode::AccessDenied);
        $d->host->denied = false; $d->policy->available = false;
        $this->rejects(fn () => $d->application->submit($submission), code: ErrorCode::AccessDenied);
        $this->rejects(fn () => $d->application->status($id, $d->context()), code: ErrorCode::AccessDenied);
        $d->policy->available = true; $d->host->revision = 'policy-2';
        $this->rejects(fn () => $d->application->submit($submission), code: ErrorCode::StaleContext);
        self::assertSame(1, $this->countRows('agentools_events'));
    }

    public function testAtomicRollbackAndImmutableOrigin(): void
    {
        $d = $this->direct; $pdo = $this->database->pdo;
        $pdo->exec('CREATE FUNCTION "' . $this->schema . '".submit_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'fault\'; END $$');
        foreach (['action_submissions', 'agentools_events', 'action_current', 'outbox'] as $table) {
            $pdo->exec('CREATE TRIGGER submit_fault BEFORE INSERT ON "' . $this->schema . '"."' . $table . '" FOR EACH ROW EXECUTE FUNCTION "' . $this->schema . '".submit_fault()');
            try { $this->rejects(fn () => $d->application->submit($d->submission()), StorageFailure::class); }
            finally { $pdo->exec('DROP TRIGGER submit_fault ON "' . $this->schema . '"."' . $table . '"'); }
            foreach (['action_origins', 'action_submissions', 'agentools_events', 'action_current', 'outbox'] as $atomic) { self::assertSame(0, $this->countRows($atomic)); }
        }
        $pdo->exec('CREATE CONSTRAINT TRIGGER submit_commit_fault AFTER INSERT ON "' . $this->schema . '".action_submissions
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION "' . $this->schema . '".submit_fault()');
        try { $this->rejects(fn () => $d->application->submit($d->submission()), StorageFailure::class); }
        finally { $pdo->exec('DROP TRIGGER submit_commit_fault ON "' . $this->schema . '".action_submissions'); }
        foreach (['action_origins', 'action_submissions', 'agentools_events', 'action_current', 'outbox'] as $atomic) { self::assertSame(0, $this->countRows($atomic)); }
        $this->rejects(fn () => $d->environment->db->execute('INSERT INTO ' . $d->environment->db->table('action_origins') . "(tenant_id,id) VALUES ('tenant-a','orphan')"));
        $id = $d->application->submit($d->submission())->view->receipt->actionId;
        foreach (['action_origins', 'action_submissions'] as $table) {
            $this->rejects(fn () => $d->environment->db->execute('DELETE FROM ' . $d->environment->db->table($table)), IntegrityConflict::class);
            $this->rejects(fn () => $d->environment->db->execute('UPDATE ' . $d->environment->db->table($table) . " SET id='replacement'"), IntegrityConflict::class);
        }
        $observer = $this->composition(pdo: (new IsolatedDatabase())->pdo);
        self::assertSame($id->value, $observer->application->status($id, $observer->context())->receipt->actionId->value);
        $pdo->beginTransaction();
        try { $this->rejects(fn () => $d->application->submit($d->submission('nested')), \LogicException::class); }
        finally { $pdo->rollBack(); }
    }

    public function testProposalMappingSurvivesRetry(): void
    {
        // Existing runtime owns normalization and mapping; submit reuses, never rewrites it.
        $d = $this->direct;
        $context = $d->context('session');
        $input = '{"query":"hello"}';
        $f = new Fixture($this->database, $this->schema, proposalHash: $d->hasher->hash($d->host->tenant, 'action-input', json_decode($input)));
        $submission = $d->submission(proposal: new ProposalId($d->host->tenant, 'proposal'), context: $context);
        $receipt = $d->application->submit($submission)->view;
        self::assertSame($f->action->value, $receipt->receipt->actionId->value);
        $fresh = $this->composition(pdo: (new IsolatedDatabase())->pdo);
        self::assertSame($receipt->receipt->toArray(), $fresh->application->submit($fresh->submission(proposal: new ProposalId($d->host->tenant, 'proposal'), context: $fresh->context('session')))->view->receipt->toArray());
        $this->rejects(fn () => $d->application->submit($d->submission('new-key', proposal: new ProposalId($d->host->tenant, 'proposal'), context: $context)), IntegrityConflict::class);
        $this->rejects(fn () => $d->application->submit($d->submission(input: '{"query":"substituted"}', proposal: new ProposalId($d->host->tenant, 'proposal'), context: $context)), IntegrityConflict::class);
        $this->rejects(fn () => $d->application->submit($d->submission('fallback', proposal: new ProposalId($d->host->tenant, 'unpersisted-fallback'), context: $context)), code: ErrorCode::AccessDenied);
        self::assertSame(1, $this->countRows('proposal_map'));
        self::assertSame(1, $this->countRows('action_submissions'));
    }

    public function testCompetingConnections(): void
    {
        $workers = [new SubmitWorker($this->schema, 'race-a'), new SubmitWorker($this->schema, 'race-b')];
        $lock = $this->direct->hasher->hash($this->direct->host->tenant, 'action-submission-lock', 'submission-1')->value;
        $pdo = $this->database->pdo;
        $pdo->beginTransaction();
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key' => $lock]);
        try { foreach ($workers as $worker) { $worker->go(); } $this->waitForLocks(2); }
        finally { $pdo->rollBack(); }
        $results = array_map(static fn (SubmitWorker $worker): array => $worker->finish(), $workers);
        foreach ($results as $result) { self::assertSame(0, $result['exit'], $result['stderr']); }
        self::assertSame($results[0]['stdout'], $results[1]['stdout']);
        foreach (['action_submissions','agentools_events','action_current','outbox'] as $table) { self::assertSame(1, $this->countRows($table)); }
    }

    public function testCrashRestart(): void
    {
        $pdo = $this->database->pdo;
        $pdo->exec('CREATE FUNCTION "' . $this->schema . '".submit_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock(5190101); RETURN NEW; END $$');
        $pdo->exec('CREATE TRIGGER submit_barrier AFTER INSERT ON "' . $this->schema . '".action_current FOR EACH ROW EXECUTE FUNCTION "' . $this->schema . '".submit_barrier()');
        $worker = new SubmitWorker($this->schema, 'before');
        $pdo->exec('SELECT pg_advisory_lock(5190101)');
        try {
            $worker->go(); $this->waitForLocks(1); $worker->kill(); $result = $worker->finish();
            self::assertNotSame(0, $result['exit']);
        } finally { $pdo->exec('SELECT pg_advisory_unlock(5190101)'); }
        $pdo->exec('DROP TRIGGER submit_barrier ON "' . $this->schema . '".action_current');
        foreach (['action_origins','action_submissions','agentools_events','outbox'] as $table) { self::assertSame(0, $this->countRows($table)); }
        $after = new SubmitWorker($this->schema, 'after'); $after->go();
        $receipt = json_decode($after->line(), true, 32, JSON_THROW_ON_ERROR);
        $after->kill(); self::assertNotSame(0, $after->finish()['exit']);
        $fresh = $this->composition(pdo: (new IsolatedDatabase())->pdo);
        self::assertSame($receipt, $fresh->application->submit($fresh->submission())->view->receipt->toArray());
        self::assertSame(1, $this->countRows('agentools_events'));
        self::assertSame(0, $this->countRows('effect_intents'));
    }

    public function testUnknownAndRecoveryFailClosed(): void
    {
        $d = $this->direct; $id = $d->application->submit($d->submission())->view->receipt->actionId;
        $this->append($id, ['action_started', 'epoch_started', 'effect_requested', 'task_binding_lost']);
        $view = $d->application->status($id, $d->context());
        self::assertSame('reconciliation_required', $view->receipt->wireStatus);
        self::assertSame(Outcome::Unknown, $view->outcome()); self::assertSame('manual_resolution', $view->safeNextStep);
        $this->rejects(fn () => $d->application->reconcile($id, new ReconcileRequest(new Ref($id->tenant, 'lookup'), 5, $d->context())), code: ErrorCode::CapabilityUnavailable);
        $cancel=$d->application->cancel($id, new CancelRequest(new Ref($id->tenant, 'cancel'), 5, $d->context()));
        self::assertSame('reconciliation_required',$cancel->view->receipt->wireStatus);
        self::assertSame(Outcome::Unknown,$cancel->view->outcome());
        self::assertSame(6, $this->countRows('agentools_events'));
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events DISABLE TRIGGER append_only');
        $this->database->pdo->exec('UPDATE "' . $this->schema . '".agentools_events SET content_hash=\'sha-256:' . str_repeat('0', 64) . '\' WHERE seq=5');
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events ENABLE TRIGGER append_only');
        $this->rejects(fn () => $d->application->status($id, $d->context()), IntegrityConflict::class);
    }

    public function testDirectExample(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/direct-proof.php', $this->schema],
            [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $error === false ? '' : $error);
        self::assertIsString($output); $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data); self::assertSame('created', $data['status']);
        foreach (['runs','turns','invocation_intents','proposal_map','effect_intents'] as $table) { self::assertSame(0, $this->countRows($table)); }
    }

    private function waitForLocks(int $expected): void
    {
        $query = $this->database->pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database()
            AND application_name LIKE :prefix AND wait_event_type='Lock'");
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $query->execute(['prefix' => 'submit_' . $this->schema . '%']); $waiting = (int) $query->fetchColumn();
            if ($waiting === $expected) { break; } usleep(1000);
        } while (hrtime(true) < $deadline);
        self::assertSame($expected, $waiting, 'Workers must actually overlap at PostgreSQL barriers');
    }

    /**
     * Test-only journal writer. Production submit/status have no dispatch API.
     * @param list<string> $kinds
     */
    private function append(ActionId $id, array $kinds): void
    {
        $db = $this->direct->environment->db;
        $db->transaction(function () use ($db, $id, $kinds): void {
            $events = $db->journal()->load($id->tenant->value, $id->value)->events;
            $reducer = new ActionReducer(hashChain: new JournalHashChain(new Canonicalizer()));
            $state = $reducer->fold($events); $previous = $state->seq;
            $pending = [];
            foreach ($kinds as $index => $kind) {
                $eventId = $id->value . ':test:' . ($index + $previous);
                $payload = [];
                if ($kind === 'effect_requested') {
                    $effectKey = (new \AgenToolsPlatform\Core\Common\IdentityKeys($this->direct->hasher))->effectKey($id, 0)->value;
                    $payload = ['effect_id' => $effectKey, 'idempotency_key' => 'owner:' . substr($effectKey, 8),
                        'request_hash' => str_repeat('b',64), 'request_ref' => 'host:effect-input', 'owner_namespace' => 'owner'];
                    $db->execute('INSERT INTO ' . $db->table('effect_intents') . '
                        (tenant_id,id,action_ref,effect_key,owner_namespace,owner_operation_key,request_hash,request_ref,request_context,claim_epoch)
                        VALUES (:tenant,:id,:action,:key,\'owner\',:operation,:hash,\'host:effect-input\',\'{}\',1)',
                        ['tenant' => $id->tenant->value, 'id' => $eventId, 'action' => $id->value, 'key' => $effectKey,
                            'operation' => $payload['idempotency_key'], 'hash' => str_repeat('b',64)]);
                }
                $pending[] = new UncommittedEvent($eventId, $kind, 1, '2026-09',
                    new Actor('host', 'owner'), '2026-10-31T19:30:00Z', $payload,
                    in_array($kind, ['epoch_started','effect_requested'], true) ? 1 : null);
            }
            $db->journal()->appendInTransaction($id->tenant->value, $id->value, $previous, $pending);
            $projection = new Projection($reducer->fold($db->journal()->load($id->tenant->value, $id->value)->events));
            $db->execute('UPDATE ' . $db->table('action_current') . ' SET version=:version,state=CAST(:state AS jsonb),digest=:digest WHERE tenant_id=:tenant AND id=:id',
                ['version' => $projection->state->seq, 'state' => $projection->json(), 'digest' => $projection->digest(), 'tenant' => $id->tenant->value, 'id' => $id->value]);
            foreach ($pending as $event) {
                $db->execute('INSERT INTO ' . $db->table('outbox') . '(tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                    VALUES (:tenant,:id,:key,:hash,\'action.journaled\',:action,\'2099-01-01Z\',\'2099-01-08Z\')',
                    ['tenant' => $id->tenant->value, 'id' => 'action.event:' . $event->eventId, 'key' => 'action.event:' . $event->eventId,
                        'hash' => str_repeat('a',64), 'action' => $id->value]);
            }
        });
    }
}
