<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Conformance\Providers;
use AgenToolsPlatform\Core\Common\{InvocationId,Ref};
use AgenToolsPlatform\Core\Models\{Deployment,EventKind,ModelEvent};
use AgenToolsPlatform\Core\Models\Routing\{CatalogEntry,FallbackPlan,FallbackRouter,OutputBoundary,OutputContext};
use AgenToolsPlatform\Tests\Routing\Fixture as Routing;

final class Fixture
{
    public Routing $routing;
    public CatalogEntry $primary;
    public CatalogEntry $alternate;
    public OutputContext $context;
    public OutputBoundary $boundary;
    public InvocationId $original;
    public function __construct()
    {
        $this->routing=new Routing(); $this->primary=$this->routing->entry();
        $this->alternate=$this->routing->entry(['id'=>'fallback-model','model_revision'=>'other-exact-revision']);
        $this->routing->states=[$this->routing->qualified($this->primary),$this->routing->qualified($this->alternate)];
        $this->original=$this->routing->modelRequest()->invocationId;
        $this->context=new OutputContext(Routing::ref('bundle'),Routing::ref('goal'),Routing::ref('state-v1'),Routing::ref('business-goal'),[Routing::ref('action-1')]);
        $this->boundary=new OutputBoundary($this->original,$this->primary,Routing::ref('segment-1'),$this->context);
    }
    public function buffer(string $text='old prefix'): void
    { $this->boundary->observe(new ModelEvent($this->original,1,EventKind::Started)); $this->boundary->observe(new ModelEvent($this->original,2,EventKind::TextDelta,delta:$text)); }
    public function plan(bool $explicit=false,bool $allowed=false,?Ref $unknownEffect=null): FallbackPlan
    {
        $state=$this->boundary->interrupt(Routing::ref('failure-1'),$unknownEffect);
        return (new FallbackRouter($this->routing->router(),$this->routing->hasher))->plan($this->routing->request(),$state,$this->routing->now(),$explicit,$allowed);
    }
    public function next(FallbackPlan $plan,string $text='new answer'): void
    {
        $id=$plan->invocation ?? throw new \RuntimeException('Missing fallback invocation');
        $this->boundary->observe(new ModelEvent($id,1,EventKind::Started)); $this->boundary->observe(new ModelEvent($id,2,EventKind::TextDelta,delta:$text));
    }
}
