<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextBlock,Provenance,SourceKind};
use AgenToolsPlatform\Core\Context\History\{Checks,Derivations,HistoryPolicy,HistorySelection,HistorySource,SourceStatus};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class MemorySelector
{
    public function __construct(private MemorySource $owner,private MemoryPolicy $policy,private HistorySource $history,
        private HistoryPolicy $historyPolicy,private Derivations $derivations,private CanonicalHasher $hasher,private int $limit=8)
    { if ($limit<1 || $limit>32) { throw new \InvalidArgumentException('Bounded memory query required'); } }
    public function select(ExecutionContext $context,Ref $subject,UtcInstant $now): HistorySelection
    {
        $context->session->tenant->assertSame($subject->tenant);$this->policy->authorize($context,$subject,'memory.export');
        $consent=$this->policy->consentVersion($context,$subject);
        if ($consent==='') { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        $page=$this->owner->queryCandidates($context,$subject,$this->limit);
        if (count($page->candidates)>$this->limit) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        $blocks=[];$excluded=[];$seen=[];
        foreach ($page->candidates as $c) {
            $subject->assertSame($c->subject);$reason=null;
            if (!in_array($c->status,[CandidateStatus::Active,CandidateStatus::Revalidated],true)) { $reason=$c->status->value; }
            elseif (!Checks::future($c->expiresAt,$now) || Checks::future($c->validFrom,$now)) { $reason='expired_or_not_valid'; }
            elseif (!$this->derivations->usable($context,$c->ref)) { $reason='source_revoked'; }
            elseif (isset($seen[$c->dedupKey->value])) { $reason='duplicate'; }
            foreach ($c->sources as $s) {
                $read=$reason===null?$this->history->read($context,$s):null;
                if (!$this->historyPolicy->sourceAllowed($context,$s)) { $reason='source_revoked'; }
                if ($read!==null && ($read->message===null || !Checks::future($read->message->expiresAt,$now))) { $reason='source_'.$read->status->value; }
                if ($read?->message!==null) {
                    $s->hash->assertSame($read->message->source->hash);$s->ref->assertSame($read->message->source->ref);$s->branch->assertSame($read->message->source->branch);
                    if ($s->version!==$read->message->source->version || $s->turn!==$read->message->source->turn) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
                }
            }
            if ($reason!==null) { $excluded[]=['id'=>$c->ref->value,'reason'=>$reason];continue; }
            $view=$this->owner->view($context,$c);
            if ($view->text===null) { $excluded[]=['id'=>$c->ref->value,'reason'=>'view_'.$view->status->value];continue; }
            $c->valueHash->assertSame($this->hasher->hash($subject->tenant,'memory-value',$view->text));
            $blocks[]=new \AgenToolsPlatform\Core\Context\ContextBlock($c->ref,'evidence',$c->ref,(string)$c->version,$c->owner,'untrusted',$c->sensitivity,
                $context->purpose,$c->valueRef,$view->text,$c->expiresAt,false,10,$this->hasher,[],
                new \AgenToolsPlatform\Core\Context\Provenance(SourceKind::Memory,array_map(static fn($r): Ref=>$r->ref,$c->sources)));
            $seen[$c->dedupKey->value]=true;
        }
        // Recheck after every external view load; a pinned candidate grants no later disclosure.
        foreach ($blocks as $block) {
            if (!$this->derivations->usable($context,$block->ref)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        }
        $selected=[];foreach ($blocks as $block) { $selected[$block->ref->value]=true; }
        foreach ($page->candidates as $candidate) {
            if (!isset($selected[$candidate->ref->value])) { continue; }
            foreach ($candidate->sources as $source) {
                $read=$this->history->read($context,$source);
                if (!$this->historyPolicy->sourceAllowed($context,$source) || $read->message===null
                    || !Checks::future($read->message->expiresAt,$now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
                $actual=$read->message->source;
                $source->ref->assertSame($actual->ref);$source->hash->assertSame($actual->hash);$source->branch->assertSame($actual->branch);
                if ($source->version!==$actual->version || $source->turn!==$actual->turn) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
            }
        }
        $this->policy->authorize($context,$subject,'memory.export');
        if ($this->policy->consentVersion($context,$subject)!==$consent) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        return new \AgenToolsPlatform\Core\Context\History\HistorySelection($page->status,$blocks,$excluded,$page->hasMore);
    }
}
