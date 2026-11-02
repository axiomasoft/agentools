<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Retrieval;

use AgenToolsPlatform\Knowledge\{Decision, Scope};
use AgenToolsPlatform\Knowledge\Retrieval\{Cache, Candidate, OwnerViews, Query, Reranker, Selection, VectorRecord};
use AgenToolsPlatform\Knowledge\Retrieval\Adapters\{ArrayCache, SemanticIndex};
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    public function testRankFusionAndRerank(): void
    {
        $f = new Fixture(); $space = Fixture::space(); $a = $f->add(space:$space); $b = $f->add(id:'b',text:'Gravity other source.',space:$space);
        $semantic = new SemanticIndex($space,[new VectorRecord('alpha',$a->hash,'fragment:1',[0.0,1.0,0.0]),new VectorRecord('alpha',$b->hash,'fragment:1',[1.0,0.0,0.0])]);
        $ranker = new class implements Reranker {
            public array $seen = [];
            public function rerank(Query $query,array $candidates): array { $this->seen = $candidates; return array_reverse($candidates); }
        };
        $q = $f->query([$a,$b],space:$space); $service = $f->service(semantic:$semantic,reranker:$ranker);
        $selection = $service->retrieve($q); self::assertCount(2,$ranker->seen); self::assertCount(2,$selection->candidates);
        self::assertSame($ranker->seen[1]->release->hash,$selection->candidates[0]->release->hash);
        self::assertSame('success',$service->disclose($q,$selection)->status);
    }

    public function testOverlapDedupAndDiversity(): void
    {
        $f = new Fixture(); $a = $f->add(overlap:true); $b = $f->add(id:'b',text:'Gravity has another source.');
        $c = $f->add(id:'c',text:'Gravity has another source.');
        $q = $f->query([$a,$b,$c]); $service = $f->service(); $result = $service->disclose($q,$service->retrieve($q));
        self::assertSame('success',$result->status); self::assertCount(2,$result->fragments);
        self::assertCount(2,array_unique(array_column(array_column($result->fragments,'citation'),'material')));
        self::assertCount(2,array_unique(array_column($result->fragments,'text')));
    }

    public function testUtf8AndBudgets(): void
    {
        $f = new Fixture(); $text = str_repeat('Введение ',40).'Гравитация притягивает массы. '.str_repeat('Конец ',20);
        $pin = $f->add(text:$text,locale:'ru'); $q = $f->query([$pin],text:'гравитация',locale:'ru',fragmentBytes:64,maxBytes:64,maxFragments:1);
        $service = $f->service(); $result = $service->disclose($q,$service->retrieve($q));
        self::assertSame('success',$result->status); $fragment = $result->fragments[0]; $c = $fragment['citation'];
        self::assertLessThanOrEqual(64,strlen($fragment['text'])); self::assertSame(1,preg_match('//u',$fragment['text']));
        self::assertStringContainsString('Гравитация',$fragment['text']);
        self::assertSame($fragment['text'],substr($text,$c['start'],$c['end']-$c['start']));
        $f->lexical->override = [new Candidate($pin,'fragment:1',1,30,1.0)];
        self::assertSame('denied',$service->retrieve($q)->status);
    }

    public function testMalformedVectors(): void
    {
        foreach ([[[1.0]],[[NAN,0.0,0.0]],[[INF,0.0,0.0]],[[0.0,0.0,0.0]],[],[[1.0,0.0,0.0],[1.0,0.0,0.0]],
            [['1.0',0.0,0.0]],[[null,0.0,0.0]],[[true,0.0,0.0]],[[new \stdClass(),0.0,0.0]],
            [[1=>1.0,2=>0.0,3=>0.0]]] as $vectors) {
            $f = new Fixture(); $space = Fixture::space(); $pin = $f->add(space:$space); $q = $f->query([$pin],space:$space);
            $f->embeddings->vectors = $vectors; $service = $f->service(semantic:new SemanticIndex($space,[]));
            $result = $service->disclose($q,$service->retrieve($q));
            self::assertSame('unavailable',$result->status); self::assertSame([],$result->fragments);
        }
        $f = new Fixture(); $space = Fixture::space(); $pin = $f->add(space:$space); $q = $f->query([$pin],space:$space);
        $service = $f->service(semantic:new SemanticIndex($space,[new VectorRecord('alpha',$pin->hash,'fragment:1',[NAN,0.0,0.0])]));
        self::assertSame('unavailable',$service->retrieve($q)->status);
    }

    public function testPoisonedCacheAndRerank(): void
    {
        $f = new Fixture(); $a = $f->add(); $b = $f->add(id:'foreign',tenant:'beta'); $q = $f->query([$a]);
        $bad = new Candidate($b,'fragment:1',0,10,1.0);
        $cache = new class($bad) implements Cache {
            public function __construct(private Candidate $candidate) {}
            public function get(string $key): ?Selection { return new Selection('forged','ready','CACHE_HIT',[$this->candidate],2000000030); }
            public function put(string $key,Selection $selection): void {}
        };
        self::assertSame('denied',$f->service(cache:$cache)->retrieve($q)->status);
        $ranker = new class($bad) implements Reranker {
            public function __construct(private Candidate $candidate) {}
            public function rerank(Query $query,array $candidates): array { return [$this->candidate]; }
        };
        self::assertSame('INVALID_RERANK',$f->service(reranker:$ranker)->retrieve($q)->code);
        $forged = new Selection($q->binding(),'ready','RETRIEVED',[new Candidate($a,'fake',0,5,1.0)],2000000030);
        self::assertSame([],$f->service()->disclose($q,$forged)->fragments);
    }

    public function testLastBoundaryAndWorkerReset(): void
    {
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $cache = new ArrayCache(1); $service = $f->service($cache);
        $selection = $service->retrieve($q); $checks = 0;
        $f->owner->onCheck = function($operation) use ($f,$pin,&$checks) {
            if ($operation === 'disclose' && ++$checks === 2) { $f->owner->decisions[$pin->material->key()] = Decision::Denied; }
        };
        self::assertSame('denied',$service->disclose($q,$selection)->status);
        $f->owner->onCheck = null; $f->owner->decisions = []; $cache->clear();
        self::assertSame('RETRIEVED',$service->retrieve($q)->code); self::assertSame(2,$f->lexical->calls);
        $f->time->value += 30;
        self::assertSame('RETRIEVED',$service->retrieve($q)->code); self::assertSame(3,$f->lexical->calls);
    }

    public function testFixturesAndOwnerViews(): void
    {
        $f = new Fixture(); $q = $f->query([$f->add()]); $service = $f->service();
        $result = $service->disclose($q,$service->retrieve($q));
        $data = json_decode(file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/retrieval/evidence.json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(json_decode(json_encode($result),true),$data['result']);
        $negative = json_decode(file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/retrieval/negative.json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(['cross-tenant','teacher-only','revoked','space-mismatch','unavailable'],array_column($negative['cases'],'id'));
        $this->expectException(\DomainException::class);
        $result->toolView(new OwnerViews('beta','raw:secret','user:secret','audit:secret'));
    }

    public function testQueryAndSelectionLimits(): void
    {
        $f = new Fixture(); $pin = $f->add();
        foreach ([fn()=>$f->query([$pin,$pin]),fn()=>$f->query([$pin],fragmentBytes:16),
            fn()=>$f->query([$pin],maxFragments:0),fn()=>$f->query([$pin],text:str_repeat('q',4097)),
            fn()=>new Candidate($pin,'fragment:1',0,3,NAN)] as $invalid) {
            try { $invalid(); self::fail('Unbounded or ambiguous request accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $q = $f->query([$pin]); $candidate = new Candidate($pin,'fragment:1',0,700,1.0);
        $selection = new Selection($q->binding(),'ready','RETRIEVED',[$candidate],2000000030);
        self::assertSame('denied',$f->service()->disclose($q,$selection)->status);
    }

    public function testCacheShapeAndLifetime(): void
    {
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]);
        $candidate = new Candidate($pin,'fragment:1',24,48,1.0);
        foreach ([['empty',[$candidate],30],['ready',[],30],['ready',array_fill(0,4,$candidate),30],
            ['ready',[$candidate],301]] as [$status,$candidates,$ttl]) {
            $cache = new class(new Selection($q->binding(),$status,'CACHED',$candidates,$f->time->value+$ttl)) implements Cache {
                public function __construct(private Selection $selection) {}
                public function get(string $key): ?Selection { return $this->selection; }
                public function put(string $key,Selection $selection): void {}
            };
            $service = $f->service($cache); $selection = $service->retrieve($q);
            self::assertSame('INVALID_CACHE',$selection->code);
            self::assertSame('denied',$selection->status);
            self::assertSame([],$service->disclose($q,$selection)->fragments);
        }
        self::assertSame(0,$f->lexical->calls);
    }

    public function testSourceChangeAtFinalRepositoryRead(): void
    {
        foreach (['source','repository'] as $boundary) {
            foreach ([Decision::Denied,Decision::Unknown] as $decision) {
                $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $service = $f->service(new ArrayCache());
                $selection = $service->retrieve($q); $service->retrieve($q);
                $gets = 0;
                $f->repository->onGet = function() use ($f,$pin,$decision,$boundary,&$gets) {
                    // The source disappears/its status becomes unknown during the last repository round trip.
                    if (++$gets !== 2) { return; }
                    if ($boundary === 'repository') {
                        $f->owner->onCheck = function($operation) use ($f,$pin,$decision) {
                            $f->owner->decisions[$pin->material->key()] = $operation === 'read' ? $decision : Decision::Allowed;
                        };
                        throw new \DomainException('Owner denied or unknown');
                    }
                    $f->sources->decisions[$pin->material->key()] = $decision;
                };
                $result = $service->disclose($q,$selection);
                self::assertSame($decision === Decision::Unknown ? 'unknown' : 'denied',$result->status);
                self::assertSame([],$result->fragments);
                self::assertSame(2,$gets);
            }
        }
    }
}
