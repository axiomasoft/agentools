<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Qualification;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, UtcInstant};
use AgenToolsPlatform\Postgres\Qualification\{Repository, Schema};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $f;
    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase(); $this->schema = 'p3_'.bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema); Schema::install($this->database->pdo,$this->schema);
        $this->f = new Fixture(new Connection($this->database->pdo,$this->schema));
    }
    protected function tearDown(): void { $this->database->dropSchema($this->schema); }
    /** @param callable(): mixed $operation */
    private function deny(callable $operation): void
    {
        try { $operation(); self::fail('Expected refusal'); } catch (ContractViolation $e) {
            self::assertContains($e->errorCode,[ErrorCode::AccessDenied,ErrorCode::DeploymentUnavailable,ErrorCode::OptimisticConflict,ErrorCode::InvalidInput]);
        }
    }
    public function testSnapshotReplayConflictAndRestart(): void
    {
        [$a] = $this->f->seed(); $this->f->repo->put($a);
        $freshDb = new IsolatedDatabase(); $fresh = new Repository(new Connection($freshDb->pdo,$this->schema),$this->f->q->f->hasher,$this->f,F::ref('admin'));
        self::assertSame($a->toArray(),$fresh->snapshot($a->ref)->toArray());
        self::assertSame($a->ref->value,$fresh->latest($a->ref->tenant,$a->tuple->hash($this->f->q->f->hasher),$this->f->q->f->now())?->ref->value);
        $this->expectException(IntegrityConflict::class);
        $this->f->repo->put($this->f->q->snapshot(issued:'2026-11-01T01:00:00Z'));
    }
    public function testImmutableSqlAndInsufficientEvidence(): void
    {
        [$a] = $this->f->seed();
        $this->deny(fn()=>$this->f->db->execute('UPDATE '.$this->f->db->table('qualification_snapshots')." SET decision='rejected'"));
        $bad = $this->f->q->snapshot('missing',executed:['A'],issued:'2026-11-02T00:00:00Z'); $this->f->repo->put($bad);
        self::assertSame('insufficient_evidence',$this->f->repo->latest($a->ref->tenant,$a->tuple->hash($this->f->q->f->hasher),$this->f->q->f->now())?->decision);
        $p = $this->f->q->rollout($a->ref,null); $this->f->repo->putPolicy($p);
        $this->deny(fn()=>$this->f->repo->publish(F::ref('scope'),F::ref('publish'),$p->ref,0,$this->f->q->f->now(),$this->f,$this->f));
        self::assertNull($this->f->repo->head(F::ref('scope')));
    }
    public function testPublicationPinsCanaryRollback(): void
    {
        [$a,$b] = $this->f->seed(); $q = $this->f->q; $repo = $this->f->repo; $scope = F::ref('scope'); $now = $q->f->now();
        $initial = $q->rollout($a->ref,null,'initial'); $repo->putPolicy($initial);
        self::assertSame(1,$repo->publish($scope,F::ref('initial-cmd'),$initial->ref,0,$now,$this->f,$this->f));
        $old = $repo->pin($scope,F::ref('active-old'),null,$now,$this->f); self::assertSame('old',$old->snapshot->value);
        $canary = $q->rollout($a->ref,$b->ref,'canary-policy'); $repo->putPolicy($canary);
        self::assertSame(2,$repo->publish($scope,F::ref('canary-cmd'),$canary->ref,1,$now,$this->f,$this->f));
        $new = $repo->pin($scope,F::ref('active-canary'),$q->measurements($b),$now,$this->f); self::assertTrue($new->canary);
        self::assertFalse($repo->pin($scope,F::ref('bounded'),$q->measurements($b),$now,$this->f)->canary);
        self::assertSame($old->toArray(),$repo->pin($scope,$old->session,$q->measurements($b),$now,$this->f)->toArray());
        $promote = $q->rollout($b->ref,null,'promoted'); $repo->putPolicy($promote);
        self::assertSame(3,$repo->publish($scope,F::ref('promote-cmd'),$promote->ref,2,$now,$this->f,$this->f));
        $rollback = $q->rollout($a->ref,null,'rollback',rollbackOf:$promote->ref); $repo->putPolicy($rollback);
        self::assertSame(4,$repo->publish($scope,F::ref('rollback-cmd'),$rollback->ref,3,$now,$this->f,$this->f));
        self::assertSame(4,$repo->publish($scope,F::ref('rollback-cmd'),$rollback->ref,3,$now,$this->f,$this->f));
        self::assertSame('old',$repo->pin($scope,F::ref('after-rollback'),null,$now,$this->f)->snapshot->value);
        self::assertSame($new->toArray(),$repo->pin($scope,$new->session,null,$now,$this->f)->toArray());
        self::assertSame(0,$q->f->calls); self::assertSame(1,$q->f->ownerOperationCount());
        $audit = $this->f->db->row('SELECT document_json FROM '.$this->f->db->table('qualification_audit')." WHERE id='rollback-cmd'");
        self::assertNotNull($audit); $doc = json_decode(Connection::text($audit,'document_json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertIsArray($doc); self::assertFalse($doc['replay_effects']); self::assertSame(0,$doc['active_pins_changed']); self::assertSame('owner-reason',$doc['reason']);
    }
    public function testEmergencyAndRevokeOverPins(): void
    {
        [$a] = $this->f->seed(); $q = $this->f->q; $p = $q->rollout($a->ref,null); $r = $this->f->repo; $r->putPolicy($p);
        $r->publish(F::ref('scope'),F::ref('pub'),$p->ref,0,$q->f->now(),$this->f,$this->f);
        try { $r->publish(F::ref('scope'),F::ref('pub'),$p->ref,1,$q->f->now(),$this->f,$this->f); self::fail('Same command with differing hash accepted'); }
        catch (IntegrityConflict) {}
        $pin = $r->pin(F::ref('scope'),F::ref('active'),null,$q->f->now(),$this->f);
        $q->f->rules = [F::rule('deployment','ru-primary')];
        $this->deny(fn()=>$r->pin(F::ref('scope'),$pin->session,null,$q->f->now(),$this->f));
        $q->f->rules = []; $q->revoked = true;
        $this->deny(fn()=>$r->pin(F::ref('scope'),$pin->session,null,$q->f->now(),$this->f));
        $q->revoked = false; self::assertSame($pin->toArray(),$r->pin(F::ref('scope'),$pin->session,null,$q->f->now(),$this->f)->toArray());
        $r->put($q->snapshot('rejected',scorers:['technical.hard'=>'fail'],issued:'2026-11-02T00:00:00Z'));
        $this->deny(fn()=>$r->pin(F::ref('scope'),$pin->session,null,$q->f->now(),$this->f));
        self::assertSame(0,$q->f->calls);
    }
    public function testBoundedCompetingAssignments(): void
    {
        [$a,$b] = $this->f->seed(); $q = $this->f->q; $p = $q->rollout($a->ref,$b->ref); $r = $this->f->repo; $r->putPolicy($p);
        $r->publish(F::ref('scope'),F::ref('pub'),$p->ref,0,$q->f->now(),$this->f,$this->f);
        $this->f->db->execute('CREATE TABLE '.$this->f->db->table('qualification_worker_ready').' (id text PRIMARY KEY)');
        $this->f->db->execute('SELECT pg_advisory_lock(812220)'); $workers = [];
        try {
            foreach (['worker-a','worker-b'] as $id) {
                $process = proc_open([PHP_BINARY,__DIR__.'/worker.php',$this->schema,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process); fclose($pipes[0]); $workers[] = [$process,$pipes];
            }
            $ready = false;
            for ($i=0;$i<200;++$i) {
                $row = $this->f->db->row('SELECT count(*) AS count FROM '.$this->f->db->table('qualification_worker_ready'));
                if ($row !== null && Connection::number($row,'count') === 2) { $ready = true; break; } usleep(10000);
            }
            self::assertTrue($ready,'Both fresh workers must reach the barrier');
        } finally { $this->f->db->execute('SELECT pg_advisory_unlock(812220)'); }
        $canaries = 0;
        foreach ($workers as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$err); self::assertIsString($out); $doc = json_decode($out,true,flags:JSON_THROW_ON_ERROR); self::assertIsArray($doc);
            if ($doc['canary'] === true) { ++$canaries; }
        }
        self::assertSame(1,$canaries);
    }
    public function testAuditFailureRollsBack(): void
    {
        [$a] = $this->f->seed(); $q = $this->f->q; $r = $this->f->repo; $p = $q->rollout($a->ref,null); $r->putPolicy($p);
        $this->f->db->execute('ALTER TABLE '.$this->f->db->table('qualification_audit')." ADD CONSTRAINT reject_audit CHECK (id <> 'rejected-audit')");
        $this->deny(fn()=>$r->publish(F::ref('scope'),F::ref('rejected-audit'),$p->ref,0,$q->f->now(),$this->f,$this->f));
        self::assertNull($r->head(F::ref('scope')));
        self::assertSame(1,$r->publish(F::ref('scope'),F::ref('good-audit'),$p->ref,0,$q->f->now(),$this->f,$this->f));
    }
    public function testAuthorizationScopeCasAndCompatibility(): void
    {
        [$a,$b] = $this->f->seed(); $q = $this->f->q; $r = $this->f->repo; $p = $q->rollout($a->ref,$b->ref); $r->putPolicy($p);
        $q->compatible = false; $this->deny(fn()=>$r->publish(F::ref('scope'),F::ref('pub'),$p->ref,0,$q->f->now(),$this->f,$this->f));
        self::assertNull($r->head(F::ref('scope'))); $q->compatible = true;
        $r->publish(F::ref('scope'),F::ref('pub'),$p->ref,0,$q->f->now(),$this->f,$this->f);
        $this->deny(fn()=>$r->publish(F::ref('scope'),F::ref('stale'),$p->ref,0,$q->f->now(),$this->f,$this->f));
        $r->pin(F::ref('scope'),F::ref('session'),null,$q->f->now(),$this->f);
        try { $r->pin(F::ref('other-scope'),F::ref('session'),null,$q->f->now(),$this->f); self::fail('Scope conflict accepted'); } catch (IntegrityConflict) {}
        $this->f->denied = true; $this->deny(fn()=>$r->put($a)); $this->f->denied = false;
        $this->deny(fn()=>$r->snapshot(F::ref('old','beta')));
        $badRollback = $q->rollout($b->ref,null,'bad-rollback',rollbackOf:$p->ref); $r->putPolicy($badRollback);
        $this->expectException(IntegrityConflict::class);
        $r->publish(F::ref('scope'),F::ref('bad-rollback-cmd'),$badRollback->ref,1,$q->f->now(),$this->f,$this->f);
    }
    public function testSchemaRevisionConflict(): void
    {
        Schema::install($this->database->pdo,$this->schema);
        $this->f->db->execute('UPDATE '.$this->f->db->table('qualification_schema_versions')." SET source_hash='tampered'");
        $this->expectException(IntegrityConflict::class); Schema::install($this->database->pdo,$this->schema);
    }
}
