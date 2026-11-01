<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, RunId, SessionId, TenantId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock, ContextPlan, ContextTarget, ReadContextCompiler, TokenCounter, Renderer, DeploymentAccess, ToolAccess, Provenance, SnapshotPins};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Tests\{JcsAdapter, TestHost};

final class Fixture implements ContextAccess, TokenCounter, Renderer, DeploymentAccess
{
    public TenantId $tenant;
    public CanonicalHasher $hasher;
    public ExecutionContext $context;
    public TurnId $turn;
    public RunId $run;
    public BundleRef $bundle;
    public UtcInstant $now;
    public ReadContextCompiler $compiler;
    /** @var list<string> */ public array $denied=[];
    public bool $deploymentAllowed=true;
    public string $countConfidence='conservative';
    public string $countVersion='scripted-utf8-byte-upper-bound/v1';
    public int $countCalls=0;
    public bool $changeConfidence=false;
    public bool $revokeOnCount=false;
    public string $framing='';
    public int $forcedCount=-1;

    public function __construct()
    {
        $this->tenant=new TenantId('context-tenant'); $this->hasher=new CanonicalHasher(new JcsAdapter());
        $this->context=ExecutionContext::fromHost(new TestHost($this->tenant),new SessionId($this->tenant,'session'),
            'support.read',new Correlation('trace','request'));
        $this->turn=new TurnId($this->tenant,'turn'); $this->run=new RunId($this->tenant,'run');
        $this->bundle=new BundleRef($this->ref('bundle'),1,new PayloadHash('sha-256:'.str_repeat('a',64)));
        $this->now=new UtcInstant('2026-11-01T12:00:00.123456Z');
        $this->compiler=new ReadContextCompiler($this,$this->hasher,$this,new Tools());
    }
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \RuntimeException('Object expected'); }
        $result=[];
        foreach ($value as $key=>$item) {
            if (!is_string($key)) { throw new \RuntimeException('String key expected'); }
            $result[$key]=$item;
        }
        return $result;
    }
    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \RuntimeException('List expected'); }
        return array_map(self::object(...),$value);
    }
    public static function number(mixed $value): int
    {
        if (!is_int($value)) { throw new \RuntimeException('Integer expected'); }
        return $value;
    }
    public function ref(string $v): Ref { return new Ref($this->tenant,$v); }
    public function block(string $id,string $kind,string $text,bool $mandatory=false,int $priority=100,
        ?Provenance $provenance=null,?UtcInstant $expiry=null,string $classification='private'): ContextBlock
    {
        return new ContextBlock($this->ref($id),$kind,$this->ref('source:'.$id),'v1',$this->ref('owner'),
            $kind === 'policy' ? 'policy' : 'untrusted',$classification,'support.read',$this->ref('content:'.$id),$text,
            $expiry ?? new UtcInstant('2030-01-01T00:00:00Z'),$mandatory,$priority,$this->hasher,[], $provenance ?? new Provenance());
    }
    /** @param list<ContextBlock> $extra */
    public function plan(array $extra=[],SnapshotPins $pins=new SnapshotPins()): ContextPlan
    {
        return $this->compiler->buildPlan($this->context,$this->turn,$this->run,$this->bundle,
            [$this->block('policy','policy','Guard',true), ...$extra,$this->block('input','input','Current question',true)],$this->now,$pins);
    }
    public function target(int $window=8000,int $reserve=2000,int $margin=500): ContextTarget {
        return new ContextTarget($this->deployment(),$window,$reserve,$margin,$reserve,'scripted-model/v1',$this->ref('capabilities:v1'),$this->bundle->hash);
    }
    public function deployment(): Ref { return $this->ref('deployment'); }
    public function version(): string { return $this->countVersion; }
    public function confidence(): string { return $this->countConfidence; }
    public function count(string $rendered): int
    {
        ++$this->countCalls;
        if ($this->changeConfidence) { $this->countConfidence='exact'; }
        if ($this->revokeOnCount) { $this->denied[]='policy'; }
        return $this->forcedCount >= 0 ? $this->forcedCount : strlen($rendered);
    }
    public function layout(): string { return 'scripted byte-counted JSON envelope with baseline block order'; }
    public function render(array $blocks): string
    {
        return $this->framing.json_encode(array_map(static fn(ContextBlock $b): array=>['kind'=>$b->kind,'trust'=>$b->trust,'text'=>$b->modelView],$blocks),JSON_THROW_ON_ERROR);
    }
    // ContextAccess and DeploymentAccess have different method signatures, so wrappers below supply the ports.
    public function permits(ExecutionContext|ContextPlan $context,ContextBlock|ContextTarget $block,?UtcInstant $now=null): bool
    {
        if ($block instanceof ContextTarget) { return $this->deploymentAllowed && $block->deployment->value === 'deployment'; }
        return !in_array($block->ref->value,$this->denied,true);
    }
}
final class Tools implements ToolAccess
{
    public function permits(ExecutionContext $context,string $toolId): bool { return $toolId === 'search'; }
}
