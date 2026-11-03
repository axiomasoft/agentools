<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Operations\FacadeTests;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__,4).'/agentools-postgres/tests/Usage/bootstrap.php';

use AgenToolsPlatform\Laravel\Operations\{Command,Facade,Schema,UsageRelay};
use AgenToolsPlatform\Postgres\Tests\Usage\{Fixture as UsageFixture,Meter};
use AgenToolsPlatform\Core\Common\Ref;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    public function testRelayOnlyExplicitUsageKeyAndRecoverLostAck(): void
    {
        $f=new UsageFixture();
        try {
            $f->invoke(); $env=$f->rig->fixture->environment; Schema::install($env->db);
            $key=$f->usageId(); $ports=new Ports(); $meter=new Meter($f); $meter->lost=true;
            $service=new Facade($env,$ports,production:false,usage:new UsageRelay($f->usage,$meter));
            $command=static fn(string $id): Command=>new Command($f->rig->context,$f->ref($id),'relay',$key->value,'usage_recovery',true,resource:'usage');
            $dry=new Command($f->rig->context,$f->ref('dry'),'relay',$key->value,'usage_recovery',resource:'usage');
            $result=$service->execute($dry); self::assertTrue($result['dry_run']);
            self::assertSame(0,(int)$f->rig->sql('SELECT count(*) FROM host_meter')->fetchColumn());
            $service->execute($command('first'));
            self::assertSame('unknown',$f->usage->exportState($key)['status']);
            self::assertSame(1,(int)$f->rig->sql('SELECT count(*) FROM host_meter')->fetchColumn());
            $f->rig->sql('UPDATE accounting_export SET next_at=clock_timestamp()-interval \'1 second\' WHERE id=:id',['id'=>$key->value]);
            $meter->lost=false; $service->execute($command('second'));
            self::assertSame('exported',$f->usage->exportState($key)['status']);
            self::assertSame(1,(int)$f->rig->sql('SELECT sum(calls) FROM host_meter')->fetchColumn());
            $service->execute($command('third'));
            self::assertSame(1,(int)$f->rig->sql('SELECT sum(calls) FROM host_meter')->fetchColumn());
            $events=(int)$f->rig->sql('SELECT count(*) FROM accounting_usage')->fetchColumn();
            self::assertGreaterThan(0,$events); self::assertSame(0,$ports->wakes);
            $ports->allowed=false;
            $this->expectException(\AgenToolsPlatform\Core\Common\ContractViolation::class);
            $service->execute($command('fourth'));
        } finally { $f->drop(); }
    }
    public function testKeyedDispatcherCannotConsumeAnotherPendingExport(): void
    {
        $f=new UsageFixture();
        try {
            $f->invoke(); $meter=new Meter($f);
            $dispatcher=new \AgenToolsPlatform\Postgres\Usage\Dispatcher($f->usage,$meter);
            self::assertFalse($dispatcher->dispatchOne($f->ref('missing-explicit-key')));
            self::assertSame(0,(int)$f->rig->sql('SELECT count(*) FROM host_meter')->fetchColumn());
            self::assertTrue($dispatcher->dispatchOne($f->usageId()));
            self::assertSame(1,(int)$f->rig->sql('SELECT count(*) FROM host_meter')->fetchColumn());
            $this->expectException(\AgenToolsPlatform\Core\Common\ContractViolation::class);
            $dispatcher->dispatchOne(new Ref(new \AgenToolsPlatform\Core\Common\TenantId('foreign'),'key'));
        } finally { $f->drop(); }
    }
}
