<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/Actions/Fixtures.php';
use AgenToolsPlatform\Core\Common\{CanonicalHasher,Ref,TenantId};
use AgenToolsPlatform\Core\Observability\{AuditSink,Event,Failure,Metric,MetricSink,Observer,Outcome,Signal,Stage};
use AgenToolsPlatform\Core\Security\{EntryPoint,TraceLinks};
use AgenToolsPlatform\Tests\{Harness as H,JcsAdapter,TestHost};
use AgenToolsPlatform\Tests\Actions\Fixtures as F;

$tenant=new TenantId('observability-alpha');$context=F::context(new TestHost($tenant));$hasher=new CanonicalHasher(new JcsAdapter());
$ref=static fn(string $id): Ref=>new Ref($tenant,$id);
$event=static fn(Outcome $outcome=Outcome::Allowed): Event=>new Event($ref('audit-event'),$context,Stage::Runtime,$outcome,EntryPoint::Raw,
    new TraceLinks(turn:$ref('turn'),run:$ref('run'),snapshot:$ref('snapshot'),invocation:$ref('invocation'),action:$ref('action'),effect:$ref('effect'),delivery:$ref('delivery')),$hasher);
$cases=[
    'CM-044'=>static function () use ($context,$hasher,$ref): void {
        $source=json_decode((string)file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/redaction/sentinels.json'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($source)) { throw new RuntimeException('Missing sentinel fixture'); }
        $named=[];foreach ($source as $key=>$value) { if (!is_string($key)) { throw new RuntimeException('Named fixture required'); }$named[$key]=$value; }
        $secret=json_encode($source,JSON_THROW_ON_ERROR);
        $error=new RuntimeException($secret,0,new LogicException($secret));
        $record=new Event($ref('nested'),$context,Stage::Diagnostics,Outcome::Failed,EntryPoint::Voice,new TraceLinks(run:$ref('run')),$hasher,$named,$error);
        $json=json_encode($record,JSON_THROW_ON_ERROR);$debug=print_r($record,true);
        foreach (['SYNTHETIC-CHILD-TEXT','SYNTHETIC-API-KEY','SYNTHETIC-PROVIDER-RESPONSE','SYNTHETIC-TOOL-TABLE'] as $value) {
            H::check(!str_contains($json,$value)&&!str_contains($debug,$value));
        }
        $setting=ini_get('zend.exception_ignore_args');ini_set('zend.exception_ignore_args','0');
        try {
            try {
                new Event($ref('rejected'),$context,Stage::Runtime,Outcome::Denied,EntryPoint::Raw,
                    new TraceLinks(effect:new Ref(new TenantId('foreign'),'effect')),$hasher,$named,$error);
                throw new LogicException('Expected cross-tenant rejection');
            } catch (InvalidArgumentException $rejected) {
                $trace=json_encode($rejected->getTrace(),JSON_THROW_ON_ERROR);
                H::check(!str_contains($trace,'SYNTHETIC-CHILD-TEXT') && !str_contains($trace,'SYNTHETIC-API-KEY'));
            }
        } finally { if ($setting!==false) { ini_set('zend.exception_ignore_args',$setting); } }
        H::check($record->metadata()['error_code']==='internal_error' && $record->metadata()['redacted']===true);
    },
    'V2ALL-PRIVACY-POSITIVE'=>static function () use ($event): void {
        $value=$event();$metadata=$value->metadata();$links=$metadata['links'];if (!is_array($links)) { throw new RuntimeException('Missing trace links'); }
        foreach (['turn','run','snapshot','invocation','action','effect','delivery'] as $kind) { H::check(isset($links[$kind.'_ref'])); }
        H::check($value->hash->value===$event()->hash->value && $value->hash->value!==$event(Outcome::Unknown)->hash->value);
        H::check(!isset($metadata['context'],$metadata['payload'],$metadata['credentials']));
    },
    'observability.bounded-metrics'=>static function (): void {
        foreach (Signal::cases() as $signal) {
            $unknown=new Metric($signal,null,Stage::Runtime,Outcome::Unknown,EntryPoint::Scheduled);
            H::check($unknown->value===null && $unknown->dimensions()['certainty']==='unknown');
            $zero=new Metric($signal,0.0,Stage::Runtime,Outcome::Succeeded,EntryPoint::Raw,certainty:'known_zero');H::check($zero->value===0.0);
        }
        foreach (['tenant-unique','run:unique','SYNTHETIC-CHILD-TEXT'] as $category) {
            H::rejects(static fn()=>new Metric(Signal::UsageUnits,1.0,Stage::Runtime,Outcome::Allowed,EntryPoint::Text,$category,'measured'),InvalidArgumentException::class);
        }
        H::rejects(static fn()=>new Metric(Signal::UsageUnits,0.0,Stage::Runtime,Outcome::Unknown,EntryPoint::Text),InvalidArgumentException::class);
        H::rejects(static fn()=>new Metric(Signal::RunLatency,-1.0,Stage::Runtime,Outcome::Allowed,EntryPoint::Text,certainty:'measured'),InvalidArgumentException::class);
        H::rejects(static fn()=>new Metric(Signal::RunLatency,INF,Stage::Runtime,Outcome::Allowed,EntryPoint::Text,certainty:'measured'),InvalidArgumentException::class);
    },
    'V2ALL-PRIVACY-NEGATIVE'=>static function () use ($context,$hasher,$ref): void {
        H::rejects(static fn()=>new Event($ref('cross-tenant'),$context,Stage::Runtime,Outcome::Allowed,EntryPoint::Raw,
            new TraceLinks(effect:new Ref(new TenantId('foreign'),'effect')),$hasher),InvalidArgumentException::class);
        H::rejects(static fn()=>new Failure('arbitrary-sensitive-message'),InvalidArgumentException::class);
    },
    'V2ALL-PRIVACY-FAULT'=>static function () use ($event): void {
        $audit=new class implements AuditSink {
            public bool $fail=false;public bool $resetFail=false;public int $calls=0;
            public function append(Event $event): Ref { ++$this->calls;if ($this->fail) { throw new RuntimeException('SYNTHETIC-API-KEY'); }return $event->id; }
            public function reset(): void { if ($this->resetFail) { throw new RuntimeException('SYNTHETIC-CHILD-TEXT'); } }
        };
        $metrics=new class implements MetricSink {
            public int $calls=0;public int $resets=0;
            public function observe(Metric $metric): void { ++$this->calls;throw new RuntimeException('SYNTHETIC-PROVIDER-RESPONSE'); }
            public function reset(): void { ++$this->resets; }
        };
        $point=new Metric(Signal::RunLatency,1.0,Stage::Runtime,Outcome::Allowed,EntryPoint::Text,certainty:'measured');
        $optional=new Observer($audit,$metrics);$optional->emit($event(),[$point]);H::check($optional->health()['metrics_degraded']);
        $required=new Observer($audit,$metrics,true);H::rejects(static fn()=>$required->emit($event(),[$point]),Failure::class);
        $audit->fail=true;$before=$metrics->calls;
        try { $optional->emit($event(),[$point]);throw new LogicException('Expected audit failure'); }
        catch (Failure $failure) { H::check($failure->reason==='audit_unavailable' && $failure->getPrevious()===null); }
        H::check($metrics->calls===$before);$audit->resetFail=true;
        H::rejects($optional->reset(...),Failure::class);H::check($metrics->resets===1 && !$optional->health()['healthy']);
        H::rejects(static fn()=>$optional->emit($event()),Failure::class);
    },
];
H::run($cases,'contract-scripted','contract-scripted');
