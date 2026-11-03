<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{IntegrityConflict,InvocationId,Ref};
use AgenToolsPlatform\Core\Models\{Deployment,FinishReason,ModelEvent,StreamState};

/** Pure attribution/exposure boundary. Host journals delivery exposure; Runtime alone executes invocations/Actions. */
final class OutputBoundary
{
    private StreamState $stream;
    private int $offset=0;
    private bool $disclosed=false;
    private bool $interrupted=false;
    private ?Ref $failure=null;
    private ?Ref $unknownEffect=null;
    private Deployment $deployment;
    private string $expectedActualModel;
    /** @var list<string> */ private array $attempted=[];
    /** @var list<array{segment:string,invocation:string,status:string}> */ private array $history=[];

    public function __construct(private InvocationId $invocation,CatalogEntry $selected,private Ref $segment,private OutputContext $context)
    {
        $this->deployment=$selected->deployment; $this->expectedActualModel=$selected->deployment->model; $deployment=$this->deployment;
        foreach ([$deployment->ref,$segment,$context->bundle] as $ref) { $invocation->tenant->assertSame($ref->tenant); }
        $this->stream=new \AgenToolsPlatform\Core\Models\StreamState($invocation);
    }
    public function observe(ModelEvent $event): void
    {
        if ($this->interrupted) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Interrupted output cannot accept more provider bytes'); }
        try {
          if ($event->response!==null) {
            $r=$event->response; $this->deployment->ref->assertSame($r->requestedDeployment->ref);
            if (!$this->deployment->sameBinding($r->requestedDeployment) || $r->requestedDeployment->model!==$this->deployment->model
                || ($r->actualDeployment!==null && (!$this->deployment->sameBinding($r->actualDeployment)
                    || $r->actualDeployment->model!==$this->expectedActualModel || $r->actualDeployment->ref->value!==$this->deployment->ref->value))) {
                $this->interrupted=true; throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Model/source changed inside one output segment');
            }
          }
          $this->stream->accept($event);
        } catch (\Throwable $error) { $this->interrupted=true; throw $error; }
        if ($event->response!==null && !in_array($event->response->finishReason,[FinishReason::Completed,FinishReason::ToolProposal],true)) { $this->interrupted=true; }
    }
    /** Returning bytes counts conservatively as exposure; it does not assert destination acknowledgement. */
    public function disclose(): ?OutputChunk
    {
        if ($this->interrupted) { throw new \LogicException('Interrupted output requires an explicit replacement segment'); }
        $text=substr($this->stream->text(),$this->offset); if ($text==='') { return null; }
        $chunk=new \AgenToolsPlatform\Core\Models\Routing\OutputChunk($this->segment,$this->invocation,$this->deployment->ref,
            $this->deployment->model,$this->stream->terminal()?->actualDeployment?->model,$this->offset,$text);
        $this->offset+=strlen($text); $this->disclosed=true; return $chunk;
    }
    public function interrupt(Ref $failure,?Ref $unknownEffect=null): FallbackState
    {
        $this->invocation->tenant->assertSame($failure->tenant);
        if ($unknownEffect!==null) { $this->invocation->tenant->assertSame($unknownEffect->tenant); $this->unknownEffect=$unknownEffect; }
        $this->interrupted=true; $this->failure=$failure;
        return new \AgenToolsPlatform\Core\Models\Routing\FallbackState($this->invocation,$this->context->bundle,$this->context->goal,$this->context->actions,
            $this->deployment->ref->value,$failure,$this->disclosed,$this->unknownEffect,$this->disclosed?$this->segment:null,$this->attempted);
    }
    public function reconcile(EffectLookup $owner): bool
    {
        if ($this->unknownEffect===null) { return true; }
        $reply=$owner->status($this->unknownEffect); $this->unknownEffect->assertSame($reply->operation);
        $accepted=false; foreach ($this->context->actions as $action) { if ($action->value===$reply->action->value && $action->tenant->value===$reply->action->tenant->value) { $accepted=true; } }
        if (!$accepted) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Owner lookup changed accepted Action'); }
        if ($reply->outcome==='unknown') { return false; }
        $this->unknownEffect=null; return true;
    }
    public function switchTo(FallbackPlan $plan,OutputContext $context): void
    {
        $this->context->assertSame($context); $this->invocation->assertSame($plan->origin->invocation);
        $this->context->bundle->assertSame($plan->origin->bundle); $this->context->goal->assertSame($plan->origin->goal);
        if (!$this->interrupted || $this->failure===null || $this->unknownEffect!==null || $plan->origin->unknownEffect!==null
            || $plan->retryActions || $plan->invocation===null || $plan->route?->selected===null || $plan->invocation->value===$this->invocation->value
            || $plan->origin->deployment!==$this->deployment->ref->value
            || $plan->origin->disclosed!==$this->disclosed || $plan->origin->failureEvent->value!==$this->failure->value) {
            throw new \LogicException('Fallback requires a known, bound interrupted origin and a new Invocation');
        }
        if (count($plan->origin->actions)!==count($context->actions)) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Fallback dropped accepted Actions'); }
        foreach ($context->actions as $i=>$action) { $action->assertSame($plan->origin->actions[$i]); }
        if ($this->disclosed) {
            if ($plan->outcome!=='new_segment' || $plan->segment===null || $plan->segment->value===$this->segment->value
                || $plan->origin->disclosedSegment?->value!==$this->segment->value) { throw new \LogicException('Disclosed models cannot share an output segment'); }
            $segment=$plan->segment;
        } else {
            if ($plan->outcome!=='reroute' || $plan->segment!==null) { throw new \LogicException('Undisclosed fallback must be an ordinary reroute'); }
            $segment=new \AgenToolsPlatform\Core\Common\Ref($plan->invocation->tenant,'segment:'.$plan->invocation->value);
        }
        $this->invocation->tenant->assertSame($plan->invocation->tenant); $this->invocation->tenant->assertSame($segment->tenant);
        $this->invocation->tenant->assertSame($plan->route->selected->deployment->ref->tenant);
        $this->history[]=['segment'=>$this->segment->value,'invocation'=>$this->invocation->value,'status'=>$this->disclosed?'interrupted':'undisclosed_discarded'];
        $this->attempted[]=$this->deployment->ref->value; $this->invocation=$plan->invocation; $this->deployment=$plan->route->selected->deployment;
        $this->expectedActualModel=$plan->route->selected->deployment->model;
        $this->invocation->tenant->assertSame($this->deployment->ref->tenant); $this->segment=$segment; $this->offset=0; $this->disclosed=false;
        $this->interrupted=false; $this->failure=null; $this->stream=new \AgenToolsPlatform\Core\Models\StreamState($this->invocation);
    }
    /** @return list<array{segment:string,invocation:string,status:string}> */
    public function history(): array { return $this->history; }
}
