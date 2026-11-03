<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Chatom;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Context\History\{ExactMessage, HistoryPage, HistorySource, MessageRead, MessageRef, SourceManifest, SourceStatus};
use AgenToolsPlatform\Core\Security\{BoundaryClock, ExecutionContext, HostAuthorization, HostIdentity};

/** Two independent synthetic support/editor hosts. No Edufy models or platform transcript store. */
final class HostHistory implements HistorySource, HostAuthorization, BoundaryClock
{
    public bool $allowed = true;
    public bool $reverse = false;
    public bool $unavailable = false;
    public bool $expired = false;
    public bool $associative = false;
    public ?\Closure $duringRead = null;
    public function __construct(public TenantId $tenant, private CanonicalHasher $hasher) {}
    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        $this->tenant->assertSame($session->tenant);
        if (!$this->allowed || !in_array($purpose,['support.read','editor.read'],true)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant,new Ref($this->tenant,'viewer'),null,new Ref($this->tenant,'grant'),'policy-1');
    }
    public function now(): UtcInstant { return new UtcInstant('2026-11-03T07:00:00Z'); }
    public function recent(ExecutionContext $context, Ref $branch, int $afterTurn, int $limit): HistoryPage
    {
        $this->authorize($context->session,$context->purpose);
        if ($this->unavailable) { return new HistoryPage(SourceStatus::Unavailable,[],false); }
        $rows=[];
        for ($i=max(1,$afterTurn+1); $i<=min(3,$afterTurn+$limit); ++$i) {
            $text='Synthetic '.($context->purpose==='support.read'?'support':'editor').' item '.$i;
            $source=new MessageRef(new Ref($this->tenant,'chatom:message:'.$i),$branch,$i,'revision-1',
                $this->hasher->hash($this->tenant,'history-message',$text));
            $rows[]=new ExactMessage($source,new Ref($this->tenant,'chatom'),$text,Classification::Private,
                new UtcInstant($this->expired?'2026-11-03T06:00:00Z':'2026-11-03T08:00:00Z'),$this->hasher);
        }
        if ($this->duringRead !== null) { ($this->duringRead)(); }
        if ($this->associative && $rows!==[]) { $rows=[7=>$rows[0]]; }
        return new HistoryPage(SourceStatus::Available,$this->reverse?array_reverse($rows):$rows,$afterTurn+$limit<3);
    }
    public function manifest(ExecutionContext $context, Ref $branch, int $throughTurn): SourceManifest
    {
        $page=$this->recent($context,$branch,0,max(1,min(32,$throughTurn)));
        return new SourceManifest($branch,$throughTurn,$page->status,$page->status===SourceStatus::Available && !$page->hasMore,
            array_map(static fn(ExactMessage $m):MessageRef=>$m->source,array_filter($page->messages,static fn(ExactMessage $m):bool=>$m->source->turn<=$throughTurn)),$this->hasher);
    }
    public function read(ExecutionContext $context, MessageRef $ref): MessageRead
    {
        $page=$this->recent($context,$ref->branch,max(0,$ref->turn-1),1);
        return $page->status===SourceStatus::Available && $page->messages!==[]
            ? new MessageRead(SourceStatus::Available,$page->messages[0]) : new MessageRead($page->status===SourceStatus::Available?SourceStatus::Deleted:$page->status);
    }
}
