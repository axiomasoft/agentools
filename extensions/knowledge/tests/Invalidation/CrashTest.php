<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\Decision;
use AgenToolsPlatform\Knowledge\Dependencies\{DeletionRequest,DeletionResult,DeletionTransport,DeletionWorker,PostgresGraph};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class CrashTest extends TestCase
{
    public function testProviderResponseCrashKeepsUnknownAndStableRetry(): void
    {
        $db=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));$db->createSchema($schema);
        try {
            $s=Fixture::scope();$graph=new PostgresGraph($db->pdo,$schema,new Policy());$graph->install();$cache=Fixture::node('cache');
            $graph->register($s,$cache);$request=new DeletionRequest($cache,'provider:r','supported',null);$graph->attach($s,$request);
            $graph->invalidate($s,$cache,'source_revoked','revoke');
            $db->pdo->exec('CREATE TABLE invalidation_provider_effects(request_id text PRIMARY KEY)');
            $worker=new Process('delete-crash',$schema,'lost-response');self::assertSame('READY',$worker->line());$worker->go();
            self::assertSame('PROVIDER_DELETED',$worker->line());$status=$worker->kill();self::assertTrue($status['signaled']);self::assertSame(9,$status['termsig']);
            $restart=new PostgresGraph((new IsolatedDatabase())->pdo,$schema,new Policy());$transport=new Transport();
            self::assertSame('unknown',(new DeletionWorker($restart,$transport))->attempt($s,$request,'lost-response')->status);
            self::assertSame(0,$transport->calls);self::assertNull($restart->result($s,$request,'lost-response'));
            $retry=new class($db->pdo) implements DeletionTransport {
                public int $calls=0;
                public function __construct(private \PDO $pdo) {}
                public function delete(DeletionRequest $request): DeletionResult
                {
                    ++$this->calls;$stmt=$this->pdo->prepare('INSERT INTO invalidation_provider_effects VALUES (?) ON CONFLICT DO NOTHING');
                    $stmt->execute([$request->id]);return new DeletionResult('succeeded','provider:stable-receipt');
                }
            };
            self::assertSame('succeeded',(new DeletionWorker($restart,$retry))->attempt($s,$request,'explicit-retry')->status);
            self::assertSame('succeeded',$graph->result($s,$request,'explicit-retry')->status);
            $effects=(int)$db->pdo->query('SELECT count(*) FROM invalidation_provider_effects')->fetchColumn();self::assertSame(1,$effects);
            Fixture::observation('delete-crash',['signal'=>$status['termsig'],'signaled'=>$status['signaled'],'worker_exit'=>$status['exitcode'],
                'lost_response'=>'unknown','replayed_io'=>$transport->calls,'new_attempt_io'=>$retry->calls,'provider_effects'=>$effects,'receipt'=>'succeeded']);
        } finally { $db->dropSchema($schema); }
    }

    public function testInvalidationCommitCrashAndDeletionRestart(): void
    {
        foreach (['crash-before','crash-after'] as $mode) {
            $db=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));$db->createSchema($schema);
            try {
                $s=Fixture::scope();$graph=new PostgresGraph($db->pdo,$schema,new Policy());$graph->install();$root=Fixture::node();$graph->register($s,$root);
                $cache=Fixture::node('cache');$graph->register($s,$cache,[$root]);$r=new DeletionRequest($cache,'provider:r','supported',null);$graph->attach($s,$r);
                $worker=new Process($mode,$schema);self::assertSame('READY',$worker->line());$worker->go();$line=$worker->line();
                if ($mode==='crash-before') { self::assertSame('PRECOMMIT',$line); }
                else { self::assertSame('committed',json_decode($line,true,flags:JSON_THROW_ON_ERROR)['status']); }
                $status=$worker->kill();self::assertTrue($status['signaled']);self::assertSame(9,$status['termsig']);
                $restart=new PostgresGraph((new IsolatedDatabase())->pdo,$schema,new Policy());
                self::assertSame($mode==='crash-before'?Decision::Allowed:Decision::Denied,$restart->usable($s,$cache));
                self::assertCount($mode==='crash-before'?0:1,$restart->requests($s));
                $restart->invalidate($s,$root,'source_revoked','event:1');$restart->invalidate($s,$root,'source_revoked','event:1');
                self::assertCount(1,$restart->requests($s));self::assertSame(Decision::Denied,$restart->usable($s,$cache));
                self::assertTrue($restart->beginAttempt($s,$r,'lost-response'));
                $transport=new Transport();$runner=new DeletionWorker(new PostgresGraph((new IsolatedDatabase())->pdo,$schema,new Policy()),$transport);
                self::assertSame('unknown',$runner->attempt($s,$r,'lost-response')->status);self::assertSame(0,$transport->calls);
                self::assertSame('succeeded',$runner->attempt($s,$r,'explicit-retry')->status);self::assertSame(1,$transport->calls);
                Fixture::observation($mode,['signal'=>$status['termsig'],'signaled'=>$status['signaled'],
                    'worker_exit'=>$status['exitcode'],'retry_tombstones'=>(int)$db->pdo->query('SELECT count(*) FROM dependency_tombstones')->fetchColumn(),
                    'delete_intents'=>count($restart->requests($s)),'lost_response'=>'unknown','replayed_io'=>0,'new_attempt_io'=>$transport->calls]);
            } finally { $db->dropSchema($schema); }
        }
    }
}
