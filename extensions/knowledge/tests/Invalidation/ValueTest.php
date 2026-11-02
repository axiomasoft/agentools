<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\Dependencies\{Node,DeletionRequest,DeletionResult};
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    public function testExactScopedIdentity(): void
    {
        $a=Fixture::node();$b=Fixture::node(content:'substitution');
        self::assertSame($a->key(),$b->key());self::assertNotSame($a->hash,$b->hash);
        self::assertNotSame($a->key(),Fixture::node(version:'v2')->key());
        self::assertNotSame($a->key(),Fixture::node(tenant:'tenant:b')->key());
        $this->expectException(\InvalidArgumentException::class);new Node('tenant:a','material','x','v1','bad');
    }
    public function testExplicitCapabilitiesAndSuccessEvidence(): void
    {
        $r=new DeletionRequest(Fixture::node('cache'),'provider:resource','unsupported','2026-12-01T00:00:00Z');
        self::assertSame($r->id,(new DeletionRequest($r->node,$r->providerRef,'unknown',null))->id);
        self::assertNotSame($r->hash(),(new DeletionRequest($r->node,$r->providerRef,'unknown',null))->hash());
        try { new DeletionRequest($r->node,$r->providerRef,'unsupported','2026-02-30T00:00:00Z');self::fail('Invalid expiry accepted'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $this->expectException(\InvalidArgumentException::class);new DeletionResult('succeeded');
    }
}
