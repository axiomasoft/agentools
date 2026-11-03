<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Observability;
require_once dirname(__DIR__).'/Operations/Fixture.php';

use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Observability\{AuditSink,DiagnosticAuthority,DiagnosticPermission,Event,Metric,MetricSink,Observer};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Laravel\Observability\{AuditJournal,DiagnosticVault,KeyResolver,Schema};

final class Grant implements DiagnosticAuthority
{
    public bool $allowed=true;public int $calls=0;public int $resets=0;
    /** @var list<string> */ public array $permissions=[];
    public function authorize(ExecutionContext $context,Ref $target,DiagnosticPermission $permission): Ref
    {
        ++$this->calls;$this->permissions[]=$permission->value;
        if (!$this->allowed) { throw new \RuntimeException('SYNTHETIC-API-KEY'); }
        return new Ref($target->tenant,'grant:'.$permission->value);
    }
    public function reset(): void { ++$this->resets; }
}
final class Keys implements KeyResolver
{
    public bool $fail=false;public bool $resetFail=false;public int $calls=0;public int $resets=0;public ?\Closure $after=null;
    /** @var array<string,string> */ public array $cache=[];
    public function assertProduction(): void { throw new \LogicException('Test resolver'); }
    public function resolve(ExecutionContext $context,Ref $key): string
    {
        ++$this->calls;$this->cache[$context->session->tenant->value]='SYNTHETIC-API-KEY';
        if ($this->fail) { throw new \RuntimeException('SYNTHETIC-API-KEY'); }
        if ($this->after!==null) { ($this->after)(); }
        return str_repeat('x',32);
    }
    public function reset(): void { ++$this->resets;if ($this->resetFail) { throw new \RuntimeException('SYNTHETIC-API-KEY'); }$this->cache=[]; }
}
final class TestClock implements Clock
{
    public int $shift=0;
    public function now(): \DateTimeImmutable { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+'.$this->shift.' seconds'); }
}
final class Audit implements AuditSink
{
    public bool $fail=false;public int $calls=0;
    public function __construct(public AuditJournal $journal) {}
    public function append(Event $event): Ref { ++$this->calls;if ($this->fail) { throw new \RuntimeException('SYNTHETIC-CHILD-TEXT'); }return $this->journal->append($event); }
    public function reset(): void { $this->journal->reset(); }
}
final class Metrics implements MetricSink
{
    public bool $fail=false;public int $resets=0;
    /** @var list<Metric> */ public array $records=[];
    public function observe(Metric $metric): void { if ($this->fail) { throw new \RuntimeException('SYNTHETIC-PROVIDER-RESPONSE'); }$this->records[]=$metric; }
    public function reset(): void { ++$this->resets; }
}
final class Fixture
{
    public \AgenToolsPlatform\Laravel\Tests\Operations\Fixture $base;
    public Grant $grant;public Keys $keys;public TestClock $clock;public Audit $audit;public DiagnosticVault $vault;
    public function __construct()
    {
        $this->base=new \AgenToolsPlatform\Laravel\Tests\Operations\Fixture();
        $env=$this->base->f->composition->environment;Schema::install($env->db);
        $this->grant=new Grant();$this->keys=new Keys();$this->clock=new TestClock();$this->audit=new Audit(new AuditJournal($env));
        $this->vault=new DiagnosticVault($env,$this->grant,$this->keys,$this->ref('key:pinned'),$this->clock,new Observer($this->audit),60,100000);
    }
    public function ref(string $id): Ref { return new Ref($this->base->run->tenant,$id); }
    public function context(): ExecutionContext { return $this->base->f->composition->context(); }
    public function expires(string $modify='+30 seconds'): UtcInstant { return new UtcInstant($this->clock->now()->modify($modify)->format('Y-m-d\TH:i:s.u\Z')); }
    public function close(): void { $this->base->close(); }
}
