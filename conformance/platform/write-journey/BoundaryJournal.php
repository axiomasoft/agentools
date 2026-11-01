<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest};
use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, ExchangeResult, InvocationCheckpoint, InvocationJournal, InvocationPlan, InvocationRefusal, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Test barriers only after the real repository transaction has committed. */
final readonly class BoundaryJournal implements InvocationJournal
{
    public function __construct(private Journey $j,private InvocationJournal $inner) {}
    public function open(ExecutionContext $c,AdvanceLease $l,InvocationPlan $p,AdmissionRequest $a): InvocationCheckpoint { return $this->inner->open($c,$l,$p,$a); }
    public function admitted(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s,Admission $a): InvocationCheckpoint { return $this->inner->admitted($c,$p,$s,$a); }
    public function outbound(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s): InvocationCheckpoint { return $this->inner->outbound($c,$p,$s); }
    public function refused(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s,InvocationRefusal $r): InvocationCheckpoint { return $this->inner->refused($c,$p,$s,$r); }
    public function result(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s,Ref $r,PayloadHash $h,ExchangeResult $x,?Ref $e=null): InvocationCheckpoint
    {
        $saved=$this->inner->result($c,$p,$s,$r,$h,$x,$e); $this->j->boundary('model-result'); return $saved;
    }
    public function submitted(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s,SavedProposal $x): InvocationCheckpoint { return $this->inner->submitted($c,$p,$s,$x); }
    public function finish(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s): InvocationCheckpoint
    {
        $saved=$this->inner->finish($c,$p,$s); if ($saved->stage()==='complete') { $this->j->boundary('delivery-intent'); } return $saved;
    }
    public function actionsComplete(ExecutionContext $c,InvocationPlan $p,InvocationCheckpoint $s): InvocationCheckpoint { return $this->inner->actionsComplete($c,$p,$s); }
}
