<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Retrieval;

use AgenToolsPlatform\Knowledge\{Decision, Scope};
use AgenToolsPlatform\Knowledge\Retrieval\{OwnerViews, ReleasePin};
use AgenToolsPlatform\Knowledge\Retrieval\Adapters\ArrayCache;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase
{
    public function testTenantCacheIsolation(): void
    {
        $f = new Fixture(); $a = $f->add(); $b = $f->add(tenant:'beta',text:'Gravity beta secret.');
        $service = $f->service(new ArrayCache());
        $qa = $f->query([$a,$b]); $ra = $service->disclose($qa,$service->retrieve($qa));
        $qb = $f->query([$a,$b],scope:new Scope('beta','actor:2','students','education',['read']));
        $rb = $service->disclose($qb,$service->retrieve($qb));
        self::assertSame('success',$ra->status); self::assertSame('success',$rb->status);
        self::assertStringNotContainsString('beta secret',json_encode($ra));
        self::assertStringNotContainsString('attracts masses',json_encode($rb));
        self::assertSame(['alpha'],array_values(array_unique(array_map(fn($r)=>$r->material->tenant,$f->lexical->seen[0]))));
        self::assertSame(['beta'],array_values(array_unique(array_map(fn($r)=>$r->material->tenant,$f->lexical->seen[1]))));
        self::assertNotSame($qa->cacheKey(),$qb->cacheKey());
    }

    public function testRevokeBeforeDisclosureAndCache(): void
    {
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $service = $f->service(new ArrayCache());
        $selection = $service->retrieve($q);
        $hit = $service->retrieve($q); self::assertSame('CACHE_HIT',$hit->code); self::assertSame(1,$f->lexical->calls);
        $f->owner->decisions[$pin->material->key()] = Decision::Denied;
        $result = $service->disclose($q,$selection);
        self::assertSame('denied',$result->status); self::assertSame([],$result->fragments);
        self::assertSame('denied',$service->retrieve($q)->status); self::assertSame(1,$f->lexical->calls);
    }

    public function testCorrectionKeepsHistory(): void
    {
        $f = new Fixture(); $old = $f->add(); $service = $f->service(new ArrayCache()); $q = $f->query([$old]);
        $selection = $service->retrieve($q); $historic = $service->disclose($q,$selection);
        $hash = hash('sha256',json_encode($historic));
        $new = $f->add(version:'v2',text:'Gravity corrected source.');
        $f->repository->invalidate($f->scope,$old->material,'corrected:v2');
        self::assertSame('denied',$service->disclose($q,$selection)->status);
        self::assertSame('denied',$service->retrieve($q)->status);
        $next = $f->query([$new],run:'run:2');
        $result = $service->disclose($next,$service->retrieve($next));
        self::assertSame('v2',$result->fragments[0]['citation']['version']);
        self::assertSame('run:2',$result->runRef); self::assertSame($hash,hash('sha256',json_encode($historic)));
    }

    public function testUnavailableAndEmpty(): void
    {
        $f = new Fixture(); $q = $f->query([$f->add()]); $f->lexical->fail = true; $service = $f->service();
        $result = $service->disclose($q,$service->retrieve($q));
        self::assertSame('unavailable',$result->status); self::assertSame([],$result->fragments);
        self::assertStringNotContainsString('sensitive owner',json_encode($result->toolView(new OwnerViews('alpha','raw:1','user:1','audit:1'))));
        $f->lexical->fail = false; $empty = $f->query($q->releases,text:'nonexistent');
        $result = $service->disclose($empty,$service->retrieve($empty));
        self::assertSame('empty',$result->status); self::assertSame([],$result->fragments);
        self::assertSame('success',$service->disclose($q,$service->retrieve($q))->status);
    }

    public function testMetadataAndRightsBeforeAdapters(): void
    {
        $f = new Fixture(); $allowed = $f->add();
        $teacher = $f->add(id:'teacher',audiences:['teachers']); $foreign = $f->add(id:'foreign',tenant:'beta');
        $locale = $f->add(id:'locale',locale:'ru'); $program = $f->add(id:'program',programs:['p2']);
        $scopes = $f->add(id:'scope',scopes:['secret']); $revoke = $f->add(id:'revoked');
        $missing = $f->add(id:'missing'); $stale = new ReleasePin($allowed->corpus,$allowed->id,str_repeat('0',64),$allowed->material,['p1']);
        $f->owner->decisions[$revoke->material->key()] = Decision::Denied;
        $f->sources->decisions[$missing->material->key()] = Decision::Denied;
        $q = $f->query([$allowed,$teacher,$foreign,$locale,$program,$scopes,$revoke,$missing]);
        $service = $f->service(); self::assertSame('ready',$service->retrieve($q)->status);
        self::assertSame([$allowed->key()],array_keys($f->lexical->seen[0]));
        self::assertNotContains($teacher->material->key(),$f->sources->calls);
        self::assertNotContains($revoke->material->key(),$f->sources->calls);
        self::assertSame('denied',$service->retrieve($f->query([$stale]))->status);
        foreach ([$teacher,$foreign,$scopes,$revoke,$missing] as $pin) { self::assertSame('denied',$service->retrieve($f->query([$pin]))->status); }
        foreach ([$locale,$program] as $pin) { self::assertSame('empty',$service->retrieve($f->query([$pin]))->status); }
        self::assertSame(1,$f->lexical->calls);
    }

    public function testUnknownMissingAndExpiry(): void
    {
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $service = $f->service();
        $f->owner->decisions[$pin->material->key()] = Decision::Unknown;
        self::assertSame('unknown',$service->retrieve($q)->status); self::assertSame(0,$f->lexical->calls);
        $f->owner->decisions = []; $f->sources->decisions[$pin->material->key()] = Decision::Unknown;
        self::assertSame('unknown',$service->retrieve($q)->status);
        $f->sources->decisions = []; $selection = $service->retrieve($q);
        $f->time->value += 30;
        self::assertSame('EVIDENCE_EXPIRED',$service->disclose($q,$selection)->code);
        $f->repository->releases = [];
        self::assertSame('denied',$service->retrieve($q)->status);
        // P8.1 repository refusal combines denied/unknown; the retrieval boundary must retain read Unknown.
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]);
        $f->repository->onGet = function() use ($f,$pin) {
            $f->owner->onCheck = function($operation) use ($f,$pin) {
                $f->owner->decisions[$pin->material->key()] = $operation === 'read' ? Decision::Unknown : Decision::Allowed;
            };
            throw new \DomainException('Owner denied or unknown');
        };
        self::assertSame('unknown',$f->service()->retrieve($q)->status);
        self::assertSame(0,$f->lexical->calls);
    }

    public function testRunAndCacheControls(): void
    {
        $f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $service = $f->service(new ArrayCache());
        $selection = $service->retrieve($q); $next = $f->query([$pin],run:'run:2');
        self::assertSame('QUERY_BINDING_MISMATCH',$service->disclose($next,$selection)->code);
        $hit = $service->retrieve($next); self::assertSame('CACHE_HIT',$hit->code);
        self::assertSame('run:2',$service->disclose($next,$hit)->runRef);
        $variants = [$f->query([$pin],scope:new Scope('alpha','actor:other','students','education',['read'])),
            $f->query([$pin],scope:new Scope('alpha','actor:1','students','other',['read'])),
            $f->query([$pin],maxFragments:1),$f->query([$pin],fragmentBytes:64),$f->query([$pin],program:null),
            $f->query([$pin],locale:'ru')];
        foreach ($variants as $variant) { self::assertNotSame($q->cacheKey(),$variant->cacheKey()); }
        self::assertSame($q->queryHash,$f->query([$pin],text:"  GRAVITY \n")->queryHash);
        $f->owner->onCheck = function($operation) use ($f,$pin) {
            if ($operation === 'retrieve') { $f->owner->decisions[$pin->material->key()] = Decision::Denied; }
        };
        self::assertSame('denied',$service->retrieve($next)->status);
    }
}
