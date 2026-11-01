<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, ContinuationPolicy, ExchangeResult, InvocationCheckpoint, InvocationRefusal};
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class InvocationTest extends TestCase
{
    private IsolatedDatabase $database;
    /** @var list<string> */
    private array $schemas = [];
    protected function setUp(): void { $this->database = new IsolatedDatabase(); }
    protected function tearDown(): void
    {
        if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
        foreach ($this->schemas as $schema) { $this->database->dropSchema($schema); }
    }

    private function rig(string $tenant = 'tenant-a'): TestRig
    {
        $schema = 'p3_' . bin2hex(random_bytes(12)); $this->schemas[] = $schema;
        $this->database->createSchema($schema);
        (new Migrator($this->database->pdo, $schema))->migrate();
        $this->database->pdo->exec('CREATE TABLE host_admissions(tenant text,key text,hash text,PRIMARY KEY(tenant,key));
            CREATE TABLE host_payloads(tenant text,id text,body text,hash text,PRIMARY KEY(tenant,id));
            CREATE TABLE host_provider(tenant text,id text,request_hash text,tools int,PRIMARY KEY(tenant,id));
            CREATE TABLE host_actions(tenant text,id text,hash text,PRIMARY KEY(tenant,id))');
        return new TestRig($this->database->pdo, $schema, $tenant);
    }

    private function fresh(TestRig $rig): TestRig
    {
        $connection = new IsolatedDatabase();
        return new TestRig($connection->pdo, $rig->schema, $rig->fixture->tenant->value);
    }

    /** @param callable(): mixed $call */
    private function rejects(callable $call, ErrorCode $code): void
    {
        try { $call(); self::fail('Expected ' . $code->value); }
        catch (ContractViolation $error) { self::assertSame($code, $error->errorCode); }
    }

    private function checkpoint(TestRig $rig): InvocationCheckpoint
    {
        $data = $rig->sql('SELECT state_ref FROM run_steps WHERE tenant_id=:tenant AND run_id=:run AND step_key=:step', [
            'tenant' => $rig->fixture->tenant->value, 'run' => $rig->plan->runId->value, 'step' => $rig->plan->stepKey])->fetchColumn();
        self::assertIsString($data);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        $saved = (new RunRepository($rig->fixture->environment))->get($rig->context, $rig->plan->runId);
        self::assertNotNull($saved);
        return new InvocationCheckpoint(new AdvanceLease($saved->id, $saved->version, $saved->fencingEpoch, 'read-only'), $decoded);
    }

    private function killAt(TestRig $rig, string $boundary): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $rig->schema, $boundary],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $deadline = hrtime(true) + 10_000_000_000;
        try {
            while (hrtime(true) < $deadline && !str_contains($out, 'boundary:' . $boundary)) {
                $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
                if (!proc_get_status($process)['running']) { break; }
                usleep(1000);
            }
            self::assertStringContainsString('boundary:' . $boundary, $out, $err);
        } finally {
            proc_terminate($process, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        $rig->expire();
    }

    private function refusalCheckpoint(TestRig $rig, bool $outbound): InvocationCheckpoint
    {
        $saved = $rig->journal->open($rig->context, $rig->lease(), $rig->plan, $rig->admission());
        $saved = $rig->journal->admitted($rig->context, $rig->plan, $saved, $rig->budget->admit($rig->admission()));
        return $outbound ? $rig->journal->outbound($rig->context, $rig->plan, $saved) : $saved;
    }

    public function testPreProviderRefusalSurvivesRestartWithoutIntent(): void
    {
        $rig = $this->rig(); $saved = $this->refusalCheckpoint($rig, false);
        $saved = $rig->journal->refused($rig->context, $rig->plan, $saved,
            InvocationRefusal::denied(new ContractViolation(ErrorCode::AccessDenied)));
        self::assertSame('refused', $saved->stage()); self::assertSame(0, $saved->data['request_count']);
        self::assertFalse($saved->data['possible_cost']);
        self::assertNull($rig->sql('SELECT claim_owner FROM runs')->fetchColumn());
        $fresh = $this->fresh($rig);
        $this->rejects(fn () => $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan), ErrorCode::AccessDenied);
        foreach (['host_provider', 'invocation_intents', 'invocation_results', 'usage_versions', 'host_actions', 'delivery_intents'] as $table) {
            self::assertSame(0, $fresh->count($table), $table);
        }
        self::assertSame(0, $fresh->sql("SELECT count(*) FROM outbox WHERE event_type='invocation.usage'")->fetchColumn());
        self::assertSame(0, $fresh->budget->releases);
    }

    public function testPreProviderRefusalAfterIntentIsKnownFailure(): void
    {
        $rig = $this->rig(); $saved = $this->refusalCheckpoint($rig, true);
        $rig->journal->refused($rig->context, $rig->plan, $saved,
            InvocationRefusal::denied(new IntegrityConflict('raw bytes must not enter metadata')));
        $fresh = $this->fresh($rig);
        try {
            $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan);
            self::fail('Expected the saved typed integrity denial');
        } catch (IntegrityConflict $error) { self::assertSame('integrity_conflict', $error->getMessage()); }
        self::assertSame(1, $fresh->count('invocation_intents'));
        self::assertSame('failed', $fresh->sql('SELECT outcome FROM invocation_results')->fetchColumn());
        foreach (['host_provider', 'usage_versions', 'host_actions', 'delivery_intents'] as $table) {
            self::assertSame(0, $fresh->count($table), $table);
        }
        self::assertSame(0, $fresh->sql("SELECT count(*) FROM outbox WHERE event_type='invocation.usage'")->fetchColumn());
        self::assertStringNotContainsString('raw bytes', (string) $fresh->sql('SELECT state_ref FROM run_steps')->fetchColumn());
    }

    public function testUnknownCannotBecomePreProviderRefusal(): void
    {
        $rig = $this->rig(); $rig->provider->lost = true;
        $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        $fresh = $this->fresh($rig);
        $saved = $fresh->journal->open($fresh->context, $fresh->lease(), $fresh->plan, $fresh->admission());
        $this->rejects(fn () => $fresh->journal->refused($fresh->context, $fresh->plan, $saved,
            InvocationRefusal::denied(new ContractViolation(ErrorCode::AccessDenied))), ErrorCode::PolicyConflict);
        self::assertSame('unknown', $fresh->sql('SELECT outcome FROM invocation_results')->fetchColumn());
        self::assertSame('unknown', $this->checkpoint($fresh)->stage());
        self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('usage_versions'));
        self::assertSame(0, $fresh->budget->releases);
    }

    public function testRefusalCommitFailureAfterIntentRecoversUnknown(): void
    {
        $rig = $this->rig(); $saved = $this->refusalCheckpoint($rig, true);
        $rig->sql("CREATE FUNCTION fail_refusal() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN IF NEW.state_ref::jsonb->>'stage'='refused' THEN
                RAISE EXCEPTION 'scripted refusal commit failure' USING ERRCODE='40001';
            END IF; RETURN NEW; END \$\$");
        $rig->sql('CREATE TRIGGER fail_refusal BEFORE UPDATE ON run_steps FOR EACH ROW EXECUTE FUNCTION fail_refusal()');
        $this->rejects(fn () => $rig->journal->refused($rig->context, $rig->plan, $saved,
            InvocationRefusal::denied(new ContractViolation(ErrorCode::DeploymentUnavailable))), ErrorCode::OptimisticConflict);
        self::assertSame('outbound', $this->checkpoint($rig)->stage());
        self::assertSame(0, $rig->count('invocation_results'));
        $rig->expire();
        $fresh = $this->fresh($rig);
        $recovered = $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan);
        self::assertSame('unknown', $recovered->stage()); self::assertTrue($recovered->data['possible_cost']);
        self::assertNull($recovered->data['request_count']); self::assertSame(0, $fresh->count('host_provider'));
        self::assertSame('unknown', $fresh->sql('SELECT outcome FROM invocation_results')->fetchColumn());
    }

    public function testLostProviderReply(): void
    {
        $rig = $this->rig(); $rig->provider->lost = true;
        $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('unknown', $saved->stage()); self::assertTrue($saved->data['possible_cost']);
        self::assertSame('unknown', $saved->data['reservation_status']);
        $usage = $saved->data['usage']; self::assertIsArray($usage); self::assertNull($usage['input_tokens']);
        $fresh = $this->fresh($rig);
        $unknown = $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan);
        self::assertSame('unknown', $unknown->stage()); self::assertSame(1, $fresh->count('host_provider'));
        $old = $fresh->plan;
        $fresh->plan = $fresh->makePlan(2, parent: $old->request->invocationId->value, reason: 'policy-approved-unknown-replacement');
        $next = $fresh->runtime()->advance($fresh->context, $unknown->lease, $fresh->plan);
        self::assertSame('waiting_action', $next->stage());
        self::assertSame(2, $fresh->count('invocation_intents')); self::assertSame(2, $fresh->count('host_admissions'));
        self::assertSame(2, $fresh->count('host_provider')); self::assertSame(0, $fresh->budget->releases);
        $row = $fresh->sql('SELECT outcome FROM invocation_results WHERE id=:id', ['id' => $old->request->invocationId->value])->fetchColumn();
        self::assertSame('unknown', $row);
    }

    public function testPersistedProposalsAfterKill(): void
    {
        $rig = $this->rig(); $this->killAt($rig, 'proposals');
        self::assertSame(1, $rig->count('proposal_map')); self::assertSame(0, $rig->count('host_actions'));
        $action = $rig->sql('SELECT action_ref FROM proposal_map')->fetchColumn();
        $saved = $this->checkpoint($rig); $resultRef = $saved->data['result_ref'];
        $fresh = $this->fresh($rig);
        self::assertSame('waiting_action', $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan)->stage());
        self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('host_actions'));
        self::assertSame($action, $fresh->sql('SELECT id FROM host_actions')->fetchColumn());
        self::assertSame($resultRef, $this->checkpoint($fresh)->data['result_ref']);
    }

    public function testMappedSubmission(): void
    {
        $rig = $this->rig(); $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('waiting_action', $saved->stage());
        self::assertSame(1, $rig->count('proposal_map')); self::assertSame(1, $rig->count('host_actions'));
        $continued = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('continued', $continued->stage()); self::assertSame(1, $rig->count('host_provider'));
    }

    public function testNoReconstruction(): void
    {
        $rig = $this->rig(); $this->killAt($rig, 'proposals');
        $fresh = $this->fresh($rig); $lease = $fresh->lease();
        $replacement = $fresh->makePlan(2, parent: $fresh->plan->request->invocationId->value, reason: 'reconstruct-accepted');
        $this->rejects(fn () => $fresh->runtime()->advance($fresh->context, $lease, $replacement), ErrorCode::PolicyConflict);
        $fresh->runtime()->advance($fresh->context, $lease, $fresh->plan);
        self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('proposal_map'));
        $ref = $this->checkpoint($fresh)->data['result_ref']; self::assertIsString($ref);
        $payload = ExchangeResult::decode($fresh->plan, $fresh->payloads->get($fresh->context,
            new Ref($fresh->fixture->tenant, $ref)), $fresh->fixture->hasher);
        self::assertSame(0, $payload->proposals[0]->ordinal); self::assertSame('p1', $payload->proposals[0]->providerId);
    }

    public function testVerifiedLookup(): void
    {
        $rig = $this->rig(); $rig->provider->lost = true;
        $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        $fresh = $this->fresh($rig);
        $saved = $fresh->runtime(true)->advance($fresh->context, $fresh->lease(), $fresh->plan);
        self::assertSame('waiting_action', $saved->stage()); self::assertArrayHasKey('unknown_evidence', $saved->data);
        self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('invocation_intents'));
        self::assertSame(2, $fresh->count('usage_versions')); self::assertSame(1, $fresh->count('proposal_map'));
        self::assertSame('unknown', $fresh->sql('SELECT outcome FROM invocation_results')->fetchColumn());
        $usage = $saved->data['usage']; self::assertIsArray($usage); self::assertSame(100, $usage['input_tokens']);
    }

    public function testUnknownCannotBecomeZeroOrSuccess(): void
    {
        $rig = $this->rig(); $rig->provider->lost = true;
        $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        $fresh = $this->fresh($rig); $lease = $fresh->lease();
        $checkpoint = $this->checkpoint($fresh);
        $checkpoint = new InvocationCheckpoint($lease, $checkpoint->data);
        $this->rejects(fn () => $fresh->journal->finish($fresh->context, $fresh->plan, $checkpoint), ErrorCode::PolicyConflict);
        $forged = ExchangeResult::response($fresh->plan, $fresh->response(), $fresh->fixture->hasher);
        $this->rejects(fn () => $fresh->journal->result($fresh->context, $fresh->plan, $checkpoint,
            new Ref($fresh->fixture->tenant, 'invented-response'), $forged->hash(), $forged), ErrorCode::PolicyConflict);
        try {
            $fresh->sql("UPDATE invocation_results SET version=version+1,outcome='success'");
            self::fail('Append-only unknown must not be overwritten');
        } catch (\PDOException $error) { self::assertSame('23505', $error->getCode()); }
        self::assertNull($fresh->sql('SELECT input_tokens FROM usage_versions')->fetchColumn());
        self::assertSame(0, $fresh->count('responses')); self::assertSame(0, $fresh->count('host_actions'));
        self::assertSame(0, $fresh->budget->releases);
    }

    public function testOrderedBoundaries(): void
    {
        $rig = $this->rig(); $events = [];
        $rig->barrier = static function (string $event) use (&$events): void { $events[] = $event; };
        $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('waiting_action', $saved->stage());
        self::assertLessThan(array_search('intent', $events, true), array_search('admission', $events, true));
        self::assertLessThan(array_search('proposals', $events, true), array_search('payload', $events, true));
        self::assertSame(1, $rig->count('usage_versions'));
        $outbox = $rig->sql("SELECT count(*) FROM outbox WHERE event_type='invocation.usage'")->fetchColumn();
        self::assertSame(1, $outbox);
        $json = $rig->sql('SELECT state_ref FROM run_steps')->fetchColumn();
        self::assertIsString($json); self::assertStringNotContainsString('Read a fact', $json);
        self::assertStringNotContainsString('arguments_json', $json);
        $identity = $saved->data['plan']; self::assertIsArray($identity);
        $deployment = $identity['deployment']; self::assertIsArray($deployment);
        self::assertSame($rig->plan->deployment->model, $deployment['model']);
    }

    public function testGuardsAndBounds(): void
    {
        foreach (['denied', 'unavailable', 'unknown'] as $outcome) {
            $rig = $this->rig(); $rig->budget->outcome = $outcome;
            $this->rejects(fn () => $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan), ErrorCode::AdmissionDenied);
            self::assertSame(0, $rig->count('host_provider')); self::assertSame(0, $rig->count('invocation_intents'));
        }
        foreach (['emergency' => ErrorCode::DeploymentUnavailable, 'cancelled' => ErrorCode::Cancelled] as $property => $code) {
            $rig = $this->rig(); $rig->guard->{$property} = true;
            $this->rejects(fn () => $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan), $code);
            self::assertSame(0, $rig->count('host_provider'));
        }
        $rig = $this->rig(); $lease = $rig->lease(); $rig->guard->instant = new UtcInstant('2030-01-01T00:00:00Z');
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $lease, $rig->plan), ErrorCode::DeadlineExceeded);
        $rig = $this->rig(); $rig->plan = $rig->makePlan(policy: new ContinuationPolicy(maxTools: 0));
        self::assertSame('bounded', $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan)->stage());
        self::assertSame(0, $rig->count('host_actions')); self::assertSame(1, $rig->count('usage_versions'));
    }

    public function testKilledWorkerBoundaries(): void
    {
        foreach (['admission','intent','provider','payload','submit','complete'] as $boundary) {
            $rig = $this->rig(); $this->killAt($rig, $boundary);
            $fresh = $this->fresh($rig);
            if ($boundary === 'complete') {
                self::assertSame(1, $fresh->count('responses')); self::assertSame(1, $fresh->count('delivery_intents'));
                self::assertSame(1, $fresh->count('host_provider'));
                $run = (new RunRepository($fresh->fixture->environment))->get($fresh->context, $fresh->plan->runId);
                self::assertSame('completed', $run?->status->value); continue;
            }
            $saved = $fresh->runtime($boundary === 'provider')->advance($fresh->context, $fresh->lease(), $fresh->plan);
            self::assertSame($boundary === 'intent' ? 'unknown' : 'waiting_action', $saved->stage(), $boundary);
            self::assertSame($boundary === 'intent' ? 0 : 1, $fresh->count('host_provider'), $boundary);
            self::assertSame(1, $fresh->count('host_admissions'), $boundary);
            self::assertSame($boundary === 'intent' ? 0 : 1, $fresh->count('host_actions'), $boundary);
        }
    }

    public function testSecondHostReadExample(): void
    {
        $rig = $this->rig('tenant-second-host'); SecondHostFixture::configure($rig);
        $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('complete', $saved->stage()); self::assertSame(1, $rig->count('responses'));
        self::assertSame(1, $rig->count('delivery_intents')); self::assertSame(0, $rig->count('effect_intents'));
        self::assertSame('pending', $rig->sql('SELECT outcome FROM delivery_intents')->fetchColumn());
        self::assertSame('batch-results:job-17', $rig->sql('SELECT destination FROM delivery_intents')->fetchColumn());
        self::assertNull($rig->sql('SELECT claim_owner FROM runs')->fetchColumn());
    }

    public function testFencingAndCompetingConnections(): void
    {
        $rig = $this->rig(); $old = $rig->lease(); $fresh = $this->fresh($rig);
        $other = new \AgenToolsPlatform\Postgres\Claims\ClaimRepository($fresh->fixture->environment);
        self::assertNull($other->claim($fresh->context, $fresh->plan->snapshot, $fresh->plan->runId, $old->version));
        $saved = $rig->journal->open($rig->context, $old, $rig->plan, $rig->admission());
        $rig->expire(); $next = $fresh->lease(); self::assertGreaterThan($old->epoch, $next->epoch);
        $this->rejects(fn () => $rig->journal->outbound($rig->context, $rig->plan, $saved), ErrorCode::StaleClaim);
        $fresh->runtime()->advance($fresh->context, $next, $fresh->plan);
        self::assertSame(1, $fresh->count('host_provider'));
    }

    public function testInvocationAndCostAndErrorBounds(): void
    {
        foreach ([new ContinuationPolicy(maxInvocations: 1), new ContinuationPolicy(maxEstimatedUnits: 10),
            new ContinuationPolicy(maxRepeatedErrors: 1)] as $policy) {
            $rig = $this->rig(); $rig->plan = $rig->makePlan(policy: $policy); $rig->provider->lost = true;
            $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
            $fresh = $this->fresh($rig); $fresh->plan = $rig->plan; $lease = $fresh->lease();
            $next = $fresh->makePlan(2, policy: $policy, parent: $rig->plan->request->invocationId->value, reason: 'approved-replacement');
            $this->rejects(fn () => $fresh->runtime()->advance($fresh->context, $lease, $next), ErrorCode::PolicyConflict);
            self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('host_admissions'));
            self::assertSame('unknown', $saved->stage());
        }
    }

    public function testResultUsageRollback(): void
    {
        $rig = $this->rig();
        $rig->sql("CREATE FUNCTION usage_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fault' USING ERRCODE='23514'; END $$");
        $rig->sql("CREATE TRIGGER usage_fault BEFORE INSERT ON usage_versions FOR EACH ROW EXECUTE FUNCTION usage_fault()");
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan), ErrorCode::InvalidInput);
        self::assertSame(0, $rig->count('invocation_results')); self::assertSame(0, $rig->count('usage_versions'));
        self::assertSame(0, $rig->count('proposal_map')); self::assertSame(0, $rig->count('host_actions'));
        self::assertSame(1, $rig->count('host_payloads'));
        $rig->sql('DROP TRIGGER usage_fault ON usage_versions'); $rig->expire();
        $fresh = $this->fresh($rig);
        self::assertSame('waiting_action', $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan)->stage());
        self::assertSame(1, $fresh->count('host_provider')); self::assertSame(1, $fresh->count('usage_versions'));
    }

    public function testResponseDeliveryRollback(): void
    {
        $rig = $this->rig(); $rig->provider->tools = false;
        $rig->sql("CREATE FUNCTION delivery_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fault' USING ERRCODE='23514'; END $$");
        $rig->sql("CREATE TRIGGER delivery_fault BEFORE INSERT ON delivery_intents FOR EACH ROW EXECUTE FUNCTION delivery_fault()");
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan), ErrorCode::InvalidInput);
        self::assertSame(0, $rig->count('responses')); self::assertSame(0, $rig->count('delivery_intents'));
        self::assertSame(1, $rig->count('invocation_results')); self::assertSame(1, $rig->count('usage_versions'));
        $rig->sql('DROP TRIGGER delivery_fault ON delivery_intents'); $rig->expire();
        $fresh = $this->fresh($rig); // Its scripted transport would propose tools if called.
        $saved = $fresh->runtime()->advance($fresh->context, $fresh->lease(), $fresh->plan);
        self::assertSame('complete', $saved->stage()); self::assertSame(1, $fresh->count('host_provider'));
        self::assertSame(1, $fresh->count('responses')); self::assertSame(1, $fresh->count('delivery_intents'));
    }

    public function testIdentityAndCurrentPermissionGuards(): void
    {
        $rig = $this->rig(); $lease = $rig->lease();
        $opened = $rig->journal->open($rig->context, $lease, $rig->plan, $rig->admission());
        $changed = $rig->makePlan(message: 'a differing request');
        try {
            $rig->runtime()->advance($rig->context, $opened->lease, $changed);
            self::fail('Same invocation with different content must conflict');
        } catch (IntegrityConflict) { self::assertSame(0, $rig->count('host_provider')); }
        $rig->fixture->host->denied = true;
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $opened->lease, $rig->plan), ErrorCode::AccessDenied);
        $rig->fixture->host->denied = false; $rig->fixture->revisions->revision = 2;
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $opened->lease, $rig->plan), ErrorCode::StaleContext);
        self::assertSame(0, $rig->count('invocation_intents')); self::assertSame(0, $rig->count('host_admissions'));
    }

    public function testReadContinuationDoesNotReplayAction(): void
    {
        $rig = $this->rig(); $rig->actions->completed = false;
        $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        $waiting = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('waiting_action', $waiting->stage());
        self::assertSame(1, $rig->count('host_provider'));
        $rig->actions->completed = true;
        $continued = $rig->runtime()->advance($rig->context, $waiting->lease, $rig->plan);
        self::assertSame('continued', $continued->stage());
        $parent = $rig->plan->request->invocationId->value;
        $rig->plan = $rig->makePlan(step: 'answer-after-read', parent: $parent, reason: 'read-results', message: 'Authorized tool result: fact');
        $rig->provider->tools = false;
        $answer = $rig->runtime()->advance($rig->context, $continued->lease, $rig->plan);
        self::assertSame('complete', $answer->stage());
        self::assertSame(2, $rig->count('host_provider')); self::assertSame(2, $rig->count('host_admissions'));
        self::assertSame(1, $rig->count('host_actions')); self::assertSame(1, $rig->count('proposal_map'));
        self::assertSame(1, $rig->count('delivery_intents'));
        $expected = (new \AgenToolsPlatform\Core\Common\IdentityKeys($rig->fixture->hasher))->delivery($rig->plan->runId, 1, $rig->plan->destination);
        self::assertSame($expected->value, $answer->data['delivery_ref']);
    }

    public function testObservedZeroAndMissingCache(): void
    {
        $rig = $this->rig(); $rig->provider->tools = false;
        $rig->provider->usage = new \AgenToolsPlatform\Core\Models\ModelUsage(0, 0);
        $saved = $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan);
        self::assertSame('complete', $saved->stage());
        self::assertSame(0, $rig->sql('SELECT input_tokens FROM usage_versions')->fetchColumn());
        $usage = $saved->data['usage']; self::assertIsArray($usage);
        self::assertSame(1, $saved->data['request_count']); self::assertSame(0, $usage['output_tokens']); self::assertNull($usage['cache_read_tokens']); self::assertNull($usage['cache_write_tokens']);
    }

    public function testFractionalDeadlineBoundary(): void
    {
        $rig = $this->rig();
        $rig->plan = $rig->makePlan(deadline: new UtcInstant('2030-01-01T00:00:00.000001Z'));
        $rig->guard->instant = new UtcInstant('2030-01-01T00:00:00Z'); $rig->provider->tools = false;
        self::assertSame('complete', $rig->runtime()->advance($rig->context, $rig->lease(), $rig->plan)->stage());
        $rig = $this->rig(); $lease = $rig->lease();
        $rig->plan = $rig->makePlan(deadline: new UtcInstant('2030-01-01T00:00:00.000001Z'));
        $rig->guard->instant = new UtcInstant('2030-01-01T00:00:00.000001Z');
        $this->rejects(fn () => $rig->runtime()->advance($rig->context, $lease, $rig->plan), ErrorCode::DeadlineExceeded);
        self::assertSame(0, $rig->count('host_provider'));
    }
}
