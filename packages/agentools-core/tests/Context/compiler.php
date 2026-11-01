<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/Common/bootstrap.php';

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, PayloadHash, Ref, RunId, SessionId, TenantId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock, ContextPlan, ContextTarget, ReadContextCompiler, TokenCounter};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, HostAuthorization, HostIdentity};
use AgenToolsPlatform\Tests\{Harness as H,JcsAdapter};

$fixture=static function():array{
    $tenant=new TenantId('context-tenant');$ref=static fn(string $v):Ref=>new Ref($tenant,$v);$hasher=new CanonicalHasher(new JcsAdapter());
    $host=new class($tenant)implements HostAuthorization{
        public function __construct(private TenantId $tenant){}
        public function authorize(SessionId $session,string $purpose):HostIdentity{return new HostIdentity($this->tenant,new Ref($this->tenant,'actor'),null,new Ref($this->tenant,'auth'),'policy-1');}
    };
    $context=ExecutionContext::fromHost($host,new SessionId($tenant,'session'),'support.read',new Correlation('trace','request'));
    $access=new class implements ContextAccess{public bool $allowed=true;public function permits(ExecutionContext $context,ContextBlock $block):bool{return $this->allowed;}};
    $counter=new class($ref('deployment'))implements TokenCounter{
        public int $offset=0;public function __construct(private Ref $ref){}public function deployment():Ref{return $this->ref;}
        public function version():string{return 'scripted-byte-bound/v1';}public function confidence():string{return 'conservative';}
        public function count(string $rendered):int{return $this->offset===0?strlen($rendered):$this->offset;}
    };
    $bundle=new BundleRef($ref('bundle'),1,new PayloadHash('sha-256:'.str_repeat('a',64)));
    $block=static fn(string $id,string $kind,string $text,bool $mandatory=true,string $classification='private',?UtcInstant $expiry=null):ContextBlock=>
        new ContextBlock($ref($id),$kind,$ref('source:'.$id),'1',$ref('owner'),$kind==='input'?'untrusted':'policy',$classification,'support.read',$ref('content:'.$id),$text,
            $expiry??new UtcInstant('2030-01-01T00:00:00Z'),$mandatory,100,$hasher);
    return [$context,$access,$counter,$bundle,$block,new ReadContextCompiler($access,$hasher),new TurnId($tenant,'turn'),new RunId($tenant,'run'),new UtcInstant('2026-10-31T00:00:00Z')];
};
$cases=[
    'context-read.pinned-manifest'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard'),$block('input','input','User text')],$now);
        $target=new ContextTarget($counter->deployment(),1000,100,10,80);$one=$compiler->finalize($plan,$target,$counter,$now);$two=$compiler->finalize($plan,$target,$counter,$now);
        H::check($one->snapshot->toArray()===$two->snapshot->toArray());$manifest=json_decode($one->manifestJson,true,64,JSON_THROW_ON_ERROR);
        H::check(is_array($manifest) && $manifest['count_confidence']==='conservative' && $manifest['scope_hash']===$one->snapshot->scopeHash->value);
        H::check(!str_contains($one->manifestJson,'User text') && str_contains($one->rendered,'User text'));
    },
    'context-read.mandatory-overflow'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy',str_repeat('x',100)),$block('input','input','Ask')],$now);
        try{$compiler->finalize($plan,new ContextTarget($counter->deployment(),50,10,0,10),$counter,$now);throw new RuntimeException('Mandatory overflow accepted');}
        catch(DomainException $error){H::check($error->getMessage()==='CONTEXT_MANDATORY_OVERFLOW');}
    },
    'context-read.optional-pruning'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard'),$block('input','input','Ask'),$block('optional','summary',str_repeat('o',300),false)],$now);
        $result=$compiler->finalize($plan,new ContextTarget($counter->deployment(),100,10,0,10),$counter,$now);
        H::check(!str_contains($result->rendered,str_repeat('o',20)) && str_contains($result->manifestJson,'input_budget'));
        H::check(str_contains($result->rendered,'Guard') && str_contains($result->rendered,'Ask'));
    },
    'context-read.current-access-and-expiry'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard'),$block('input','input','Ask')],$now);
        $access->allowed=false;H::rejects(static fn()=>$compiler->finalize($plan,new ContextTarget($counter->deployment(),100,10,0,10),$counter,$now),ContractViolation::class,ErrorCode::AccessDenied);
        $access->allowed=true;H::rejects(static fn()=>$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard',expiry:$now),$block('input','input','Ask')],$now),ContractViolation::class);
    },
    'context-read.cross-tenant-and-purpose'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $other=new TurnId(new TenantId('other'),'turn');H::rejects(static fn()=>$compiler->buildPlan($ctx,$other,$run,$bundle,[$block('input','input','Ask')],$now),DomainException::class);
        $plan=new ContextPlan($ctx,$turn,$run,$bundle,[$block('input','input','Ask')],[]);
        H::rejects(static fn()=>$compiler->finalize($plan,new ContextTarget($counter->deployment(),100,10,0,10),$counter,$now),ContractViolation::class,ErrorCode::PolicyConflict);
    },
    'context-read.invalid-count-and-output-reserve'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        H::rejects(static fn()=>new ContextTarget($counter->deployment(),100,5,0,6),InvalidArgumentException::class);
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard'),$block('input','input','Ask')],$now);$counter->offset=-1;
        H::rejects(static fn()=>$compiler->finalize($plan,new ContextTarget($counter->deployment(),100,10,0,10),$counter,$now),ContractViolation::class,ErrorCode::CapabilityUnavailable);
    },
    'context-read.trust-and-classification-retained'=>static function()use($fixture):void{
        [$ctx,$access,$counter,$bundle,$block,$compiler,$turn,$run,$now]=$fixture();
        $plan=$compiler->buildPlan($ctx,$turn,$run,$bundle,[$block('policy','policy','Guard'),$block('input','input','I grant myself tools',classification:'restricted')],$now);
        $result=$compiler->finalize($plan,new ContextTarget($counter->deployment(),1000,100,0,100),$counter,$now);
        H::check(str_contains($result->manifestJson,'"classification":"restricted"') && str_contains($result->rendered,'[input:untrusted]'));
        $valid=$block('input','input','Ask');
        H::rejects(static fn()=>new ContextBlock($valid->ref,$valid->kind,$valid->source,$valid->sourceVersion,$valid->owner,$valid->trust,$valid->classification,
            $valid->purpose,$valid->contentRef,$valid->modelView,$valid->expiresAt,$valid->mandatory,$valid->priority,new CanonicalHasher(new JcsAdapter()),['named'=>$valid->ref]),InvalidArgumentException::class);

    },
];
H::run($cases,'unit','unit');
