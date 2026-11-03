<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Tests\Operations\FacadeTests;
require_once dirname(__DIR__).'/Fixture.php';

use AgenToolsPlatform\Laravel\Operations\{Authority,Command,Facade,Finding,Host,Observation,Schema};
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Postgres\Delivery\DeliveryClaim;
use AgenToolsPlatform\Laravel\Tests\Operations\Fixture as Existing;

final class Ports implements Host,Authority
{
    public int $authorizations=0;
    public bool $allowed=true;
    public ?\AgenToolsPlatform\Laravel\Operations\EffectLookup $effectLookup=null;
    public ?int $revokeAt=null;
    public bool $fake=false;
    public bool $expired=false;
    public bool $probeFails=false;
    public bool $loseDelivery=false;
    public bool $throwDelivery=false;
    public int $wakes=0;
    public int $lookups=0;
    public int $deliveries=0;
    /** @var list<string> */ public array $keys=[];
    public function authority(): Authority { return $this; }
    public function authorize(Command $command): void
    {
        ++$this->authorizations;
        if (!$this->allowed || ($this->revokeAt!==null && $this->authorizations>=$this->revokeAt)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }
    public function assertProduction(): void { if ($this->fake) { throw new \LogicException('secret must not escape'); } }
    public function diagnose(Command $command): array
    {
        if ($this->probeFails) { throw new \RuntimeException('credential=do-not-print'); }
        $r=[];
        foreach ([...\AgenToolsPlatform\Laravel\Operations\Doctor::HOST_CHECKS,'pg_isolation'] as $name) {
            $bad=($name==='production_adapters'&&$this->fake)||($name==='capability_evidence'&&$this->expired);
            $r[]=new Finding($name,$bad?'not_ready':'ready',$bad?'expired_or_fake':'verified');
        }
        return $r;
    }
    public function wake(Command $command,string $run): void { ++$this->wakes; $this->keys[]=$run; }
    public function reconcile(Command $command,Observation $observation,\Closure $reauthorize): ?Ref
    {
        $reauthorize(); ++$this->lookups; $this->keys[]=$observation->ownerKey??$observation->key;
        if ($observation->kind==='effect' && $this->effectLookup!==null) { return $this->effectLookup->lookup($command,$observation,$reauthorize); }
        return null; // A scripted unavailable owner is explicitly unresolved.
    }
    public function deliver(Command $command,DeliveryClaim $claim,\Closure $reauthorize): ?Ref
    {
        $reauthorize(); ++$this->deliveries; $this->keys[]=$claim->submission->key;
        if ($this->throwDelivery) { throw new \RuntimeException('destination secret'); }
        return $this->loseDelivery?null:new Ref($command->id->tenant,'destination:original-key-receipt');
    }
}
final class Fixture
{
    public Existing $existing;
    public Ports $ports;
    public Facade $service;
    public function __construct(?string $schema=null)
    {
        $this->existing=new Existing($schema); $this->ports=new Ports();
        Schema::install($this->existing->f->composition->environment->db);
        $this->service=new Facade($this->existing->f->composition->environment,$this->ports,$this->existing->delivery(),production:false);
    }
    public function command(string $verb,string $key='ops-command',bool $apply=false,?string $target=null,int $limit=50,?string $after=null): Command
    {
        $context=$this->existing->f->composition->context();
        return new Command($context,new Ref($context->session->tenant,$key),$verb,$target??$this->existing->run->value,'incident_triage',$apply,$limit,$after);
    }
    public function close(): void { $this->existing->close(); }
}
