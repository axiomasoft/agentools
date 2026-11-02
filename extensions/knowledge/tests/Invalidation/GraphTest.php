<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\{Decision,Integrity};
use AgenToolsPlatform\Knowledge\Dependencies\{DeletionRequest,DeletionResult,DeletionWorker,Node,PostgresGraph};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    private IsolatedDatabase $db;private string $schema;private PostgresGraph $graph;private Policy $policy;
    protected function setUp(): void
    {
        $this->db=new IsolatedDatabase();$this->schema='p3_'.bin2hex(random_bytes(12));$this->db->createSchema($this->schema);
        $this->policy=new Policy();$this->policy->pdo=$this->db->pdo;
        $this->graph=new PostgresGraph($this->db->pdo,$this->schema,$this->policy);$this->graph->install();
    }
    protected function tearDown(): void { $this->db->dropSchema($this->schema); }
    private function denied(\Closure $call): void
    { try { $call();self::fail('Expected rejection'); } catch (\DomainException) { self::assertTrue(true); } }
    public function testTransitiveCorrectionAndHistoricalMarkers(): void
    {
        $s=Fixture::scope();$root=Fixture::node();$this->graph->register($s,$root);$previous=$root;$nodes=[$root];
        foreach (['fragment','card','release','bundle','snapshot','cache'] as $kind) {
            $node=Fixture::node($kind,$kind.':1');$this->graph->register($s,$node,[$previous]);$nodes[]=$node;$previous=$node;
        }
        $unrelated=Fixture::node(ref:'unrelated');$this->graph->register($s,$unrelated);
        $shared=Fixture::node('bundle','shared');$this->graph->register($s,$shared,[$unrelated,$previous]);
        $this->graph->recordUse($s,'run:historic',[$shared]);$before=$this->db->pdo->query('SELECT content_hash FROM dependency_runs')->fetchColumn();
        $this->graph->invalidate($s,$root,'source_corrected','event:1');$this->graph->invalidate($s,$root,'source_corrected','event:1');
        foreach ([...$nodes,$shared] as $node) { self::assertSame(Decision::Denied,$this->graph->usable($s,$node)); }
        self::assertSame(Decision::Allowed,$this->graph->usable($s,$unrelated));
        $v2=Fixture::node(version:'v2',content:'corrected');$this->graph->register($s,$v2);
        self::assertSame(Decision::Allowed,$this->graph->usable($s,$v2));
        $this->denied(fn()=>$this->graph->register($s,Fixture::node('cache','late'),[$previous]));
        $this->denied(fn()=>$this->graph->recordUse($s,'run:new',[$shared]));
        $this->graph->recordUse($s,'run:historic',[$shared]);
        $markers=$this->graph->markers($s,'run:historic');self::assertCount(1,$markers);
        self::assertSame('source_corrected',$markers[0]['reason']);self::assertSame($root->hash,$markers[0]['source']->hash);
        self::assertSame($before,$this->db->pdo->query('SELECT content_hash FROM dependency_runs')->fetchColumn());
    }
    public function testMessageDeleteConsentAndUnseenRoot(): void
    {
        $s=Fixture::scope();
        foreach (['source_deleted','source_revoked','consent_revoked'] as $reason) {
            $root=Fixture::node('message',$reason);$this->graph->register($s,$root);$children=[];
            foreach (['summary','memory','history_index','context','cache'] as $kind) {
                $node=Fixture::node($kind,$reason.':'.$kind);$this->graph->register($s,$node,[$root]);$children[]=$node;
            }
            $this->graph->recordUse($s,'run:'.$reason,$children);$this->graph->invalidate($s,$root,$reason,'event:'.$reason);
            foreach ($children as $node) { self::assertSame(Decision::Denied,$this->graph->usable($s,$node)); }
            $this->denied(fn()=>$this->graph->register($s,Fixture::node('message',$reason,'v2')));
            self::assertSame($reason,$this->graph->markers($s,'run:'.$reason)[0]['reason']);
        }
        $unseen=Fixture::node('message','unseen');$this->graph->invalidate($s,$unseen,'source_deleted','unseen:event');
        $this->denied(fn()=>$this->graph->register($s,$unseen));
    }
    public function testIdentityScopeCurrentPolicyAndSqlGuards(): void
    {
        $s=Fixture::scope();$root=Fixture::node();$child=Fixture::node('cache');$this->graph->register($s,$root);$this->graph->register($s,$child,[$root]);
        $this->denied(fn()=>$this->graph->register($s,Fixture::node(content:'forged')));
        $this->denied(fn()=>$this->graph->register($s,$child,[]));
        $this->denied(fn()=>$this->graph->register(Fixture::scope('tenant:b'),$root));
        $this->denied(fn()=>$this->graph->register($s,Fixture::node('summary'),[Fixture::node(tenant:'tenant:b')]));
        self::assertSame(Decision::Denied,$this->graph->usable(Fixture::scope('tenant:b'),$root));
        foreach ([Decision::Unknown,Decision::Denied] as $decision) { $this->policy->decision=$decision;self::assertSame($decision,$this->graph->usable($s,$child)); }
        $this->policy->decision=Decision::Allowed;
        self::assertSame(Decision::Denied,$this->graph->usable($s,Fixture::node('cache','missing')));
        $this->graph->invalidate($s,$root,'source_revoked','event');
        $this->denied(fn()=>$this->graph->invalidate($s,$root,'source_deleted','event'));
        foreach (['UPDATE dependency_nodes SET version=version','DELETE FROM dependency_tombstones',
            "INSERT INTO dependency_edges VALUES ('tenant:b','".$child->key()."','".$root->key()."')"] as $sql) {
            try { $this->db->pdo->exec($sql);self::fail('SQL invariant bypassed'); }
            catch (\PDOException $e) { self::assertContains($e->getCode(),['23514','23503']); }
        }
    }
    public function testDurableDeletionReceiptsRetryAndResidue(): void
    {
        $s=Fixture::scope();$root=Fixture::node();$cache=Fixture::node('cache');$this->graph->register($s,$root);$this->graph->register($s,$cache,[$root]);
        $r=new DeletionRequest($cache,'provider:r','supported','2026-12-01T00:00:00Z');$this->graph->attach($s,$r);
        $this->denied(fn()=>$this->graph->beginAttempt($s,$r,'premature'));
        $this->graph->invalidate($s,$root,'source_revoked','revoke');self::assertCount(1,$this->graph->requests($s));
        $transport=new Transport();$transport->pdo=$this->db->pdo;$transport->next=new DeletionResult('failed');
        $worker=new DeletionWorker($this->graph,$transport);self::assertSame('failed',$worker->attempt($s,$r,'a1')->status);
        $transport->next=new DeletionResult('succeeded','provider:receipt');
        self::assertSame('failed',$worker->attempt($s,$r,'a1')->status);self::assertSame(1,$transport->calls);
        self::assertSame('succeeded',$worker->attempt($s,$r,'a2')->status);self::assertSame([$r->id,$r->id],$transport->keys);
        $restart=new PostgresGraph((new IsolatedDatabase())->pdo,$this->schema,$this->policy);
        self::assertSame('succeeded',$restart->result($s,$r,'a2')->status);
        self::assertTrue($restart->beginAttempt($s,$r,'sql-invalid'));
        $sql=$this->db->pdo->prepare('INSERT INTO dependency_delete_results VALUES (?,?,?,?,?,?)');
        try { $sql->execute([$s->tenant,$r->id,'sql-invalid','succeeded',null,str_repeat('a',64)]);self::fail('SQL success without receipt accepted'); }
        catch (\PDOException $e) { self::assertSame('23514',$e->getCode()); }
        $this->denied(fn()=>$restart->finishAttempt($s,$r,'a2',new DeletionResult('unknown')));
        $this->denied(fn()=>$restart->beginAttempt($s,new DeletionRequest($cache,'provider:r','unknown',null),'a3'));
        self::assertSame(Decision::Denied,$restart->usable($s,$cache));
        $unseen=Fixture::node('cache','unsupported');$this->graph->register($s,$unseen);
        $unsupported=new DeletionRequest($unseen,'provider:u','unsupported','2026-12-01T00:00:00Z');$this->graph->attach($s,$unsupported);
        $this->graph->invalidate($s,$unseen,'source_deleted','unsupported:event');
        self::assertSame('unsupported',$worker->attempt($s,$unsupported,'u1')->status);self::assertSame(2,$transport->calls);
        $all=$this->graph->requests($s);self::assertCount(2,$all);
        $first=$this->graph->requests($s,1);$second=$this->graph->requests($s,1,$first[0]->id);
        self::assertSame(array_map(fn($q)=>$q->id,$all),[$first[0]->id,$second[0]->id]);
        foreach ($all as $request) { self::assertSame('2026-12-01T00:00:00Z',$request->knownExpiry); }
    }
}
