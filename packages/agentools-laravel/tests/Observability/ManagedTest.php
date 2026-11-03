<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Observability;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__).'/Composition/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};
use AgenToolsPlatform\Core\Observability\Failure;
use AgenToolsPlatform\Laravel\Managed\{Entry,Executor,HostBindings,Input};
use AgenToolsPlatform\Laravel\Observability\{AuditJournal,ObservedTransport,Schema,Telemetry,TraceGraph};
use AgenToolsPlatform\Laravel\Tests\Composition\Fixture as Runtime;
use PHPUnit\Framework\TestCase;

final class ManagedTest extends TestCase
{
    /** @var list<Telemetry> */ private array $telemetry=[];
    private bool $auditFails=false;private bool $metricsRequired=false;
    private function attach(Runtime $f,Metrics $metrics): void
    {
        $f->app->scoped(HostBindings::class,function () use ($f,$metrics): HostBindings {
            $p=$f->bindings();$env=$f->current()->environment;Schema::install($env->db);
            $audit=new Audit(new AuditJournal($env));$audit->fail=$this->auditFails;
            $telemetry=new Telemetry($p->hasher,$audit,$metrics,$this->metricsRequired);$this->telemetry[]=$telemetry;
            return $f->last=new HostBindings($p->connection,$p->replayConnection,$p->schema,$p->hasher,$p->json,$p->authorization,
                $p->revisions,$p->registry,$p->policy,$p->schemas,$p->confirmation,$p->keys,$p->sealer,$p->clock,
                $p->transport,$p->budget,$p->guard,$p->payloads,$p->encrypted,$p->host,$telemetry);
        });
    }
    public function testEveryEntryHasSafeTraceAndBoundedProviderMetrics(): void
    {
        $f=new Runtime();$metrics=new Metrics();$this->attach($f,$metrics);
        try {
            foreach (Entry::cases() as $entry) {
                $f->instance='observed-'.$entry->value;
                $receipt=$f->app->make(Executor::class)->execute(new Input('host:observed',$entry));
                self::assertNotNull($receipt->acknowledgement);self::assertSame([],$f->trace->state);
                self::assertSame(0,$f->current()->count('example_owner_writes'));
            }
            $j=$f->current();$rows=$j->db->execute('SELECT document FROM '.$j->db->table('observability_events'))->fetchAll(\PDO::FETCH_COLUMN);
            self::assertNotEmpty($rows);$json=json_encode($rows,JSON_THROW_ON_ERROR);
            foreach (['SYNTHETIC','alpha secret','presentationJson','inputJson','request_ref','credential'] as $forbidden) { self::assertStringNotContainsString($forbidden,$json); }
            self::assertStringContainsString('snapshot_ref',$json);self::assertStringContainsString('invocation_ref',$json);
            self::assertStringContainsString('run_ref',$json);self::assertStringContainsString('delivery_ref',$json);self::assertStringContainsString('action_ref',$json);
            $measured=0;
            foreach ($metrics->records as $metric) {
                self::assertSame(['stage','outcome','entry','category','certainty'],array_keys($metric->dimensions()));
                self::assertStringNotContainsString('alpha',json_encode($metric->dimensions(),JSON_THROW_ON_ERROR));
                if ($metric->signal->value==='provider_latency_ms' && $metric->value!==null) { ++$measured; }
            }
            self::assertSame(7,$measured);self::assertSame(8,count($this->telemetry));
            foreach ($this->telemetry as $telemetry) { self::assertSame(['healthy'=>true,'metrics_degraded'=>false],$telemetry->health()); }
        } finally { $f->close(); }
    }
    public function testAuditRequiredOptionalMetricsVisibleAndNoGuardBypass(): void
    {
        $f=new Runtime();$metrics=new Metrics();$metrics->fail=true;$this->attach($f,$metrics);
        try {
            $f->app->make(Executor::class)->execute(new Input('host:optional',Entry::Raw));
            self::assertTrue($this->telemetry[0]->health()['metrics_degraded']);self::assertSame(1,$f->current()->count('write_provider'));
            $f->instance='audit-failure';$this->auditFails=true;
            try { $f->app->make(Executor::class)->execute(new Input('host:audit-failure'));self::fail('Mandatory audit cannot degrade'); }
            catch (ContractViolation $e) { self::assertSame(ErrorCode::PolicyConflict,$e->errorCode);self::assertStringNotContainsString('SYNTHETIC',$e->getMessage()); }
            self::assertSame(1,$f->current()->count('write_provider'));self::assertSame([],$f->trace->state);
            $this->auditFails=false;$this->metricsRequired=true;$f->instance='metrics-required';
            try { $f->app->make(Executor::class)->execute(new Input('host:metrics-required'));self::fail('Host-required metrics cannot degrade'); }
            catch (ContractViolation $e) { self::assertSame(ErrorCode::PolicyConflict,$e->errorCode); }
            self::assertSame(1,$f->current()->count('write_provider'));
            $metrics->fail=false;$this->metricsRequired=false;
            foreach ([Entry::Raw,Entry::Build,Entry::Stream,Entry::Voice,Entry::Background] as $entry) {
                $f->instance='deny-observed-'.$entry->value;$f->trace->deny='deployment';
                try { $f->app->make(Executor::class)->execute(new Input('host:denied',$entry));self::fail('Telemetry cannot authorize runtime'); }
                catch (ContractViolation $e) { self::assertSame(ErrorCode::AccessDenied,$e->errorCode); }
                self::assertSame(1,$f->current()->count('write_provider'));self::assertSame([],$f->trace->state);
            }
        } finally { $f->close(); }
    }
    public function testUnknownProviderAndDeliveryKeepSeparateOutcomes(): void
    {
        $f=new Runtime();$metrics=new Metrics();$this->attach($f,$metrics);
        try {
            $f->trace->rawFailure='model';
            try { $f->app->make(Executor::class)->execute(new Input('host:unknown-provider',Entry::Stream));self::fail('Unknown provider cannot disclose'); }
            catch (ContractViolation $e) { self::assertSame(ErrorCode::InvocationUnknown,$e->errorCode); }
            $j=$f->current();$row=$j->db->row('SELECT count(*) AS n FROM '.$j->db->table('observability_events').
                ' WHERE document->>\'stage\'=\'provider\' AND document->>\'outcome\'=\'unknown\'');
            self::assertNotNull($row);self::assertGreaterThan(0,$row['n']);self::assertSame(0,$j->count('write_messages'));
            $rows=$j->db->execute('SELECT document FROM '.$j->db->table('observability_events'))->fetchAll(\PDO::FETCH_COLUMN);
            self::assertStringNotContainsString('alpha secret',json_encode($rows,JSON_THROW_ON_ERROR));
            $f->trace->rawFailure=null;$f->trace->unknownDelivery=true;$f->instance='unknown-delivery-observed';
            $receipt=$f->app->make(Executor::class)->execute(new Input('host:unknown-delivery',Entry::Voice));self::assertNull($receipt->acknowledgement);
            $j=$f->current();$row=$j->db->row('SELECT count(*) AS n FROM '.$j->db->table('observability_events').
                ' WHERE document->>\'stage\'=\'delivery\' AND document->>\'outcome\'=\'unknown\'');
            self::assertNotNull($row);self::assertSame(1,\AgenToolsPlatform\Postgres\Runtime\Connection::number($row,'n'));self::assertSame([],$f->trace->state);
        } finally { $f->close(); }
    }
    public function testRecycledWorkerAndRetiredTransportKeepTenantIsolation(): void
    {
        $f=new Runtime();$metrics=new Metrics();$this->attach($f,$metrics);
        try {
            $f->app->make(Executor::class)->execute(new Input('host:alpha'));$alpha=$f->current();$old=$f->last;self::assertNotNull($old);
            $plan=$alpha->activePlan;self::assertNotNull($plan);
            try { (new ObservedTransport($old->transport,$this->telemetry[0]))->generate($plan->request,$plan->deployment);self::fail('Retired trace context'); }
            catch (Failure $e) { self::assertSame('reset_failed',$e->reason); }
            self::assertSame(1,$alpha->count('write_provider'));
            $f->tenant='beta';$f->instance='observed-beta';$f->app->make(Executor::class)->execute(new Input('host:beta',Entry::Voice));
            $beta=$f->current();$rows=$beta->db->execute('SELECT document FROM '.$beta->db->table('observability_events').' WHERE tenant_id=\'beta\'')->fetchAll(\PDO::FETCH_COLUMN);
            self::assertNotEmpty($rows);self::assertStringNotContainsString('alpha',json_encode($rows,JSON_THROW_ON_ERROR));
            self::assertSame([],$f->trace->state);self::assertSame(2,count($this->telemetry));
            $links=(new TraceGraph($beta->environment))->read($beta->context,$beta->run,100);
            self::assertNotEmpty($links);foreach ($links as $link) { self::assertSame('beta',$link->run?->tenant->value); }
        } finally { $f->close(); }
    }
}
