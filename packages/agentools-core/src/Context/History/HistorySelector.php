<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextBlock,Provenance,SourceKind};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class HistorySelector
{
    public function __construct(private HistorySource $source,private HistoryPolicy $policy,private Derivations $derivations,
        private SummaryViews $views,private CanonicalHasher $hasher,private int $maxRecent=16)
    {
        if ($maxRecent<1 || $maxRecent>32) { throw new \InvalidArgumentException('Bounded relevance window required'); }
    }
    /** @param list<MessageRef> $mandatoryExact */
    public function select(ExecutionContext $context,Ref $branch,WorkingState $state,?SummaryVersion $summary,array $mandatoryExact,UtcInstant $now): HistorySelection
    {
        $context->session->tenant->assertSame($branch->tenant);$branch->assertSame($state->value->branch);$this->policy->authorize($context,$branch,'history.select');
        if (!Checks::future($state->expiresAt,$now) || count($mandatoryExact)>16) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        $blocks=[];$exclusions=[];$after=0;$seen=[];
        foreach ($mandatoryExact as $ref) {
            $branch->assertSame($ref->branch);
            if (isset($seen[$ref->ref->value])) { continue; }
            $read=$this->source->read($context,$ref);
            if ($read->message===null || !$this->policy->sourceAllowed($context,$ref) || !Checks::future($read->message->expiresAt,$now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
            $this->same($ref,$read->message->source);$blocks[]=$this->message($read->message,$context,$now,true);$seen[$ref->ref->value]=true;
        }
        if ($summary!==null) {
            $branch->assertSame($summary->sources->branch);$reason=null;
            if (!Checks::future($summary->expiresAt,$now)) { $reason='expired'; }
            elseif (!$this->derivations->usable($context,$summary->ref)) { $reason='source_revoked'; }
            foreach ($summary->sources->messages as $ref) {
                if (!$this->policy->sourceAllowed($context,$ref) && $reason!=='expired') { $reason='source_revoked'; }
                if (isset($seen[$ref->ref->value]) && $reason===null) { $reason='overlaps_mandatory_exact'; }
            }
            $view=$reason===null?$this->views->read($context,$summary):new \AgenToolsPlatform\Core\Context\History\TextRead(SourceStatus::Unavailable);
            if ($reason===null && $view->text===null) { $reason='summary_view_'.$view->status->value; }
            if ($reason===null) {
                $summary->viewHash->assertSame($this->hasher->hash($branch->tenant,'history-summary-view',$view->text));
                $blocks[]=new \AgenToolsPlatform\Core\Context\ContextBlock($summary->ref,'summary',$branch,(string)$summary->version,$branch,'untrusted',$summary->classification,
                    $context->purpose,$summary->fields->viewRef,$view->text,$summary->expiresAt,false,20,$this->hasher,[],
                    new \AgenToolsPlatform\Core\Context\Provenance(SourceKind::Summary,array_map(static fn(MessageRef $r): Ref=>$r->ref,$summary->sources->messages),
                        new \AgenToolsPlatform\Core\Common\Ref($branch->tenant,'history-turn:'.$summary->sources->throughTurn),(string)$summary->version,$summary->createdAt));
                $after=$summary->sources->throughTurn;
            } else { $exclusions[]=['id'=>$summary->ref->value,'reason'=>$reason]; }
        }
        $page=$this->source->recent($context,$branch,$after,$this->maxRecent);
        if (count($page->messages)>$this->maxRecent) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        foreach ($page->messages as $message) {
            $ref=$message->source;$branch->assertSame($ref->branch);
            if ($ref->turn<=$after || isset($seen[$ref->ref->value])) { continue; }
            if (!$this->policy->sourceAllowed($context,$ref) || !Checks::future($message->expiresAt,$now)) { $exclusions[]=['id'=>$ref->ref->value,'reason'=>'source_unusable'];continue; }
            $blocks[]=$this->message($message,$context,$now,false);$seen[$ref->ref->value]=true;
        }
        $stateId=new \AgenToolsPlatform\Core\Common\Ref($branch->tenant,'working:'.substr($this->hasher->hash($branch->tenant,'working-state',$state->toArray())->value,8));
        $blocks[]=new \AgenToolsPlatform\Core\Context\ContextBlock($stateId,'working_state',$branch,(string)$state->version,$branch,'untrusted','private',
            $context->purpose,$stateId,json_encode($state->toArray(),JSON_THROW_ON_ERROR),$state->expiresAt,true,100,$this->hasher,
            [$state->acceptance],new \AgenToolsPlatform\Core\Context\Provenance(SourceKind::Host));
        return new \AgenToolsPlatform\Core\Context\History\HistorySelection($page->status,$blocks,$exclusions,$page->hasMore);
    }
    private function same(MessageRef $expected,MessageRef $actual): void
    {
        $expected->ref->assertSame($actual->ref);$expected->hash->assertSame($actual->hash);$expected->branch->assertSame($actual->branch);
        if ($expected->version!==$actual->version || $expected->turn!==$actual->turn) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
    }
    private function message(ExactMessage $message,ExecutionContext $context,UtcInstant $now,bool $mandatory): ContextBlock
    {
        $r=$message->source;
        $id=new \AgenToolsPlatform\Core\Common\Ref($r->ref->tenant,'history:'.sprintf('%016d',$r->turn).':'.substr($this->hasher->hash($r->ref->tenant,'history-block-id',$r->toArray())->value,8));
        return new \AgenToolsPlatform\Core\Context\ContextBlock($id,'recent',$r->ref,$r->version,$message->owner,'untrusted',$message->classification,
            $context->purpose,$r->ref,$message->text,$message->expiresAt,$mandatory,$mandatory?100:50,$this->hasher,[],
            new \AgenToolsPlatform\Core\Context\Provenance(SourceKind::History,[$r->ref],freshAt:$now));
    }
}
