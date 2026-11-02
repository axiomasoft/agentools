<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\{Decision,Integrity,Material,Scope};
use AgenToolsPlatform\Knowledge\Adapters\Postgres\Repository;
use AgenToolsPlatform\Knowledge\Dependencies\{Node,PostgresGraph};
use AgenToolsPlatform\Knowledge\Retrieval\{Clock,Query,ReleasePin,Service,SourceAvailability};
use AgenToolsPlatform\Knowledge\Retrieval\Adapters\{ArrayCache,LexicalIndex};
use AgenToolsPlatform\Knowledge\Tests\Fixture as Ingest;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private IsolatedDatabase $db;private string $schema;private Ingest $f;private Repository $repository;private PostgresGraph $graph;
    protected function setUp(): void
    {
        $this->db=new IsolatedDatabase();$this->schema='p3_'.bin2hex(random_bytes(12));$this->db->createSchema($this->schema);
        $this->f=new Ingest();$this->repository=new Repository($this->db->pdo,$this->schema,$this->f->owner);$this->repository->install();
        $this->graph=new PostgresGraph($this->db->pdo,$this->schema,new Policy());
    }
    protected function tearDown(): void { $this->db->dropSchema($this->schema); }
    public function testExplicitCorrectionPreservesNewVersionAndDeleteTombstone(): void
    {
        $s=$this->f->scope;$old=$this->f->release();$this->repository->publish($s,$old);
        $next=new Ingest(version:'v2');$new=$next->release('release:2');$this->repository->publish($s,$new);
        $this->graph->recordUse($s,'run:old',[Node::release($old)]);
        $this->repository->invalidate($s,$old->material,'source_corrected');
        self::assertSame(Decision::Denied,$this->graph->usable($s,Node::release($old)));
        self::assertSame(Decision::Allowed,$this->graph->usable($s,Node::release($new)));
        self::assertSame('source_corrected',$this->graph->markers($s,'run:old')[0]['reason']);
        $this->graph->recordUse($s,'run:new',[Node::release($new)]);$this->repository->invalidate($s,$new->material,'delete');
        self::assertSame('source_deleted',$this->graph->markers($s,'run:new')[0]['reason']);
        $third=new Ingest(version:'v3');$this->expectException(\DomainException::class);
        $this->repository->publish($s,$third->release('release:3'));
    }
    public function testCorrectionStaleCacheAndHistoricEvidence(): void
    {
        $old=$this->f->release();$this->repository->publish($this->f->scope,$old);
        $bundle=Fixture::node('bundle');$snapshot=Fixture::node('snapshot');$cache=Fixture::node('cache');
        $this->graph->register($this->f->scope,$bundle,[Node::release($old)]);$this->graph->register($this->f->scope,$snapshot,[$bundle]);$this->graph->register($this->f->scope,$cache,[$snapshot]);
        $this->graph->recordUse($this->f->scope,'run:1',[$cache]);
        $time=new class implements Clock { public function now(): int { return 2000000000; } };
        $sources=new class implements SourceAvailability { public function check(Scope $scope,Material $material): Decision { return Decision::Allowed; } };
        $service=new Service($this->repository,$this->f->owner,$sources,$time,new LexicalIndex(),cache:new ArrayCache());
        $pin=new ReleasePin($old->corpus,$old->id,$old->hash(),$old->material,[]);
        $query=new Query($this->f->scope,'run:1','текст',[$pin],'ru');
        $selection=$service->retrieve($query);$historic=$service->disclose($query,$selection);self::assertSame('success',$historic->status);
        self::assertSame('CACHE_HIT',$service->retrieve($query)->code);$bytes=json_encode($historic,JSON_THROW_ON_ERROR);
        $next=new Ingest(version:'v2',text:'Исправленный текст.');$new=$next->release('release:2');$this->repository->publish($next->scope,$new,$old->material);
        foreach ([$bundle,$snapshot,$cache] as $node) { self::assertSame(Decision::Denied,$this->graph->usable($this->f->scope,$node)); }
        self::assertSame('denied',$service->retrieve($query)->status);self::assertSame('denied',$service->disclose($query,$selection)->status);
        self::assertSame($bytes,json_encode($historic,JSON_THROW_ON_ERROR));self::assertSame('source_corrected',$this->graph->markers($this->f->scope,'run:1')[0]['reason']);
        self::assertSame(Decision::Allowed,$this->graph->usable($next->scope,Node::release($new)));
        $this->graph->invalidate($next->scope,Node::material($new->material),'source_deleted','delete:graph');
        $this->expectException(\DomainException::class);$this->repository->get($next->scope,$new->corpus,$new->id);
    }
    public function testFailedCorrectionRollsBackBothGraphsAndDeletes(): void
    {
        $old=$this->f->release();$this->repository->publish($this->f->scope,$old);$next=new Ingest(version:'v2');
        try { $this->repository->publish($next->scope,$next->release(),$old->material);self::fail('Conflicting release published'); }
        catch (\DomainException) { self::assertTrue(true); }
        self::assertSame(0,(int)$this->db->pdo->query('SELECT count(*) FROM dependency_tombstones')->fetchColumn());
        self::assertSame(0,(int)$this->db->pdo->query('SELECT count(*) FROM dependency_deletions')->fetchColumn());
        self::assertSame(Decision::Allowed,$this->graph->usable($this->f->scope,Node::release($old)));
        $this->repository->link($this->f->scope,$old,'cache','cache:repo');
        self::assertTrue($this->repository->dependencyUsable($this->f->scope,$old,'cache','cache:repo'));
        $this->repository->invalidate($this->f->scope,$old->material,'source_revoked');
        self::assertFalse($this->repository->dependencyUsable($this->f->scope,$old,'cache','cache:repo'));
    }
}
