<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\{Decision,Integrity};
use AgenToolsPlatform\Knowledge\Dependencies\PostgresGraph;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    public function testRegistrationCompetesWithRevocation(): void
    {
        $db=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));$db->createSchema($schema);
        try {
            $graph=new PostgresGraph($db->pdo,$schema,new Policy());$graph->install();$graph->register(Fixture::scope(),Fixture::node());
            $db->pdo->beginTransaction();$stmt=$db->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');
            $stmt->execute([Integrity::digest(['dependencies/v1','tenant:a'])]);
            $a=new Process('register',$schema);$b=new Process('invalidate',$schema);
            self::assertSame('READY',$a->line());self::assertSame('READY',$b->line());$a->go();$b->go();
            $competed=false;
            for ($i=0;$i<500;++$i) {
                if ((int)$db->pdo->query("SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND database=(SELECT oid FROM pg_database WHERE datname=current_database())")->fetchColumn()>=2) { $competed=true;break; }
                usleep(10000);
            }
            $db->pdo->commit();self::assertTrue($competed,'Both real connections must wait for the held graph lock');
            $left=json_decode($a->line(),true,flags:JSON_THROW_ON_ERROR);$right=json_decode($b->line(),true,flags:JSON_THROW_ON_ERROR);
            self::assertSame(0,$a->finish());self::assertSame(0,$b->finish());self::assertNotSame($left['backend'],$right['backend']);
            self::assertSame('committed',$right['status']);self::assertContains($left['status'],['committed','denied']);
            self::assertSame(Decision::Denied,$graph->usable(Fixture::scope(),Fixture::node('cache','race')));
            Fixture::observation('concurrency',['competed'=>$competed,'backends'=>[$left['backend'],$right['backend']],
                'registration'=>$left['status'],'revocation'=>$right['status'],'final_use'=>'denied']);
        } finally { if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }$db->dropSchema($schema); }
    }
}
