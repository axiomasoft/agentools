<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Observability;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__).'/Operations/Facade/Fixture.php';

use AgenToolsPlatform\Core\Observability\Failure;
use AgenToolsPlatform\Laravel\Observability\Telemetry;
use AgenToolsPlatform\Laravel\Operations\{Command,Facade,Schema};
use AgenToolsPlatform\Laravel\Tests\Operations\FacadeTests\Ports;
use PHPUnit\Framework\TestCase;

final class OperationsTest extends TestCase
{
    public function testReadOnlyInspectionAndAuditedRecoveryStaySeparate(): void
    {
        $f=new Fixture();
        try {
            $env=$f->base->f->composition->environment;Schema::install($env->db);$ports=new Ports();$metrics=new Metrics();
            $telemetry=new Telemetry($env->hasher,$f->audit,$metrics);
            $facade=new Facade($env,$ports,production:false,telemetry:$telemetry);
            $make=static fn(string $verb,bool $apply=false): Command=>new Command($f->context(),$f->ref('observed-'.$verb),$verb,$f->base->run->value,'triage',$apply);
            $facade->execute($make('doctor'));$facade->execute($make('inspect-run'));
            self::assertSame(0,$f->base->f->count('observability_events'));self::assertSame(0,$f->base->f->count('ops_facade_intents'));
            $f->audit->fail=true;
            try { $facade->execute($make('recover',true));self::fail('No recovery without mandatory trace audit'); }
            catch (Failure $e) { self::assertSame('audit_unavailable',$e->reason); }
            self::assertSame(0,$ports->wakes);self::assertSame(0,$f->base->f->count('ops_facade_intents'));
            $f->audit->fail=false;$result=$facade->execute($make('recover',true));
            self::assertSame('recorded',$result['status']);self::assertSame(1,$ports->wakes);
            self::assertSame(2,$f->base->f->count('observability_events'));
        } finally { $f->close(); }
    }
}
