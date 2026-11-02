<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\Dependencies\{DeletionRequest,DeletionResult,DeletionWorker};
use PHPUnit\Framework\TestCase;

final class DeletionTest extends TestCase
{
    public function testReplayAndCrashedAttemptDoNotRepeatIo(): void
    {
        $store=new Attempts();$transport=new Transport();$worker=new DeletionWorker($store,$transport);
        $request=new DeletionRequest(Fixture::node('cache'),'provider:r','supported',null);$scope=Fixture::scope();
        self::assertSame('succeeded',$worker->attempt($scope,$request,'a1')->status);
        self::assertSame('succeeded',$worker->attempt($scope,$request,'a1')->status);self::assertSame(1,$transport->calls);
        self::assertTrue($store->beginAttempt($scope,$request,'crashed'));
        self::assertSame('unknown',$worker->attempt($scope,$request,'crashed')->status);self::assertSame(1,$transport->calls);
    }
    public function testUnknownUnsupportedAndExplicitRetry(): void
    {
        $store=new Attempts();$transport=new Transport();$worker=new DeletionWorker($store,$transport);$scope=Fixture::scope();
        foreach (['unknown','unsupported'] as $support) {
            $r=new DeletionRequest(Fixture::node('cache',$support),'provider:'.$support,$support,'2026-12-01T00:00:00Z');
            self::assertSame($support,$worker->attempt($scope,$r,'a1')->status);
        }
        self::assertSame(0,$transport->calls);
        $r=new DeletionRequest(Fixture::node('cache'),'provider:r','supported',null);$transport->throws=true;
        self::assertSame('unknown',$worker->attempt($scope,$r,'a1')->status);$transport->throws=false;
        self::assertSame('unknown',$worker->attempt($scope,$r,'a1')->status);
        self::assertSame('succeeded',$worker->attempt($scope,$r,'a2')->status);
        self::assertSame([$r->id,$r->id],$transport->keys);
    }
}
