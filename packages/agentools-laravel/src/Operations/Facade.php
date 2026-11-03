<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;
use AgenToolsPlatform\Postgres\Payloads\PayloadRepository;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;

/** One bounded operation. No provider/effect-capable loop and no retry-everything mode. */
final readonly class Facade
{
    public function __construct(private RepositoryContext $environment,private Host $host,
        private ?DeliveryRepository $deliveries=null,private ?PayloadRepository $payloads=null,private bool $production=true,private ?UsageRelay $usage=null,private ?\AgenToolsPlatform\Laravel\Observability\Telemetry $telemetry=null) {}
    /** @return array<string,mixed> */
    public function execute(Command $c): array
    {
        if ($this->telemetry===null || in_array($c->verb,['doctor','inspect-run'],true)) { return $this->executeOperation($c); }
        try {
            if ($this->production) { $this->telemetry->assertProduction(); }
            $this->authorize($c);
            $links=new \AgenToolsPlatform\Core\Security\TraceLinks(
                run:$c->resource==='run'?new Ref($c->id->tenant,$c->target):null,
                delivery:$c->resource==='delivery'?new Ref($c->id->tenant,$c->target):null,
                usage:$c->resource==='usage'?new Ref($c->id->tenant,$c->target):null,decision:$c->id);
            $this->telemetry->record($c->context,\AgenToolsPlatform\Laravel\Managed\Entry::Background,
                \AgenToolsPlatform\Core\Observability\Stage::Recovery,\AgenToolsPlatform\Core\Observability\Outcome::Waiting,$links);
            $result=$this->executeOperation($c);
            $this->telemetry->record($c->context,\AgenToolsPlatform\Laravel\Managed\Entry::Background,
                \AgenToolsPlatform\Core\Observability\Stage::Recovery,($result['status']??null)==='unknown'
                    ?\AgenToolsPlatform\Core\Observability\Outcome::Unknown:\AgenToolsPlatform\Core\Observability\Outcome::Allowed,$links);
            return $result;
        } finally { $this->telemetry->reset(); }
    }

    /** @return array<string,mixed> */
    private function executeOperation(Command $c): array
    {
        $this->authorize($c);
        if ($c->verb==='doctor') {
            $result=(new Doctor($this->environment,$this->host,$this->production))->inspect($c);
            $this->authorize($c); return $result;
        }
        if ($this->production) {
            \AgenToolsPlatform\Laravel\Managed\HostBindings::assertProductionService($this->host);
            $this->usage?->assertProduction();
        }
        $journal=new Journal($this->environment,$this->host->authority());
        if ($c->verb!=='inspect-run' && ($saved=$journal->begin($c))!==null) { return $saved; }
        $all=(new Inspector($this->environment,$this->host->authority()))->inspect($c);
        $page=array_slice($all,0,$c->limit);
        $items=[];
        foreach ($page as $o) {
            $this->authorize($c);
            $result='dry_run'; $receipt=null;
            if ($c->apply) {
                // Re-read before dispatch; never trust the scanner snapshot across committed operation intent.
                $fresh=(new Inspector($this->environment,$this->host->authority()))->inspect($c);
                $current=null;
                foreach ($fresh as $candidate) { if ($candidate->kind===$o->kind && $candidate->key===$o->key) { $current=$candidate; break; } }
                if ($current===null || $current->version!==$o->version || $current->epoch!==$o->epoch || $current->route!==$o->route) {
                    throw new ContractViolation(ErrorCode::OptimisticConflict);
                }
                $reauthorize=function () use ($c): void { $this->authorize($c); };
                $this->authorize($c);
                if ($c->verb==='recover' && $o->route==='wake_same_run') {
                    // Unknown subordinate work must go through reconcile, even if its Run lease is stale.
                    $unsafe=(new Inspector($this->environment,$this->host->authority()))->hasUnknown($c);
                    if ($unsafe) { $result='reconciliation_required'; }
                    else { $this->host->wake($c,$o->key); $result='wake_requested'; }
                } elseif (in_array($c->verb,['recover','reconcile'],true) && in_array($o->route,
                    ['owner_lookup_or_manual','provider_lookup_or_explicit_new_invocation','submit_saved_proposal'],true)) {
                    $receipt=$this->host->reconcile($c,$o,$reauthorize);
                    $result=$receipt===null?'unknown':'owner_recorded';
                } elseif ($c->verb==='relay' && $o->route==='relay_same_key') {
                    $deliveries=$this->deliveries;
                    if ($deliveries===null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
                    $claim=$deliveries->claim($c->context,$c->target);
                    if ($claim===null) { $result='not_claimed'; }
                    else {
                        $check=function () use ($c,$claim,$deliveries): void {
                            $this->authorize($c); $deliveries->assertCurrent($c->context,$claim->submission,$claim->token());
                        };
                        $check();
                        // A thrown transport leaves its committed claim unknown; never records failure/zero.
                        $receipt=$this->host->deliver($c,$claim,$check);
                        $this->authorize($c);
                        $deliveries->settle($c->context,$claim,$receipt===null?'unknown':'success',$receipt);
                        $result=$receipt===null?'unknown':'delivered';
                    }
                } elseif ($c->verb==='relay' && $o->route==='relay_same_usage') {
                    if ($this->usage===null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
                    $result=$this->usage->dispatch($c,$reauthorize);
                } elseif ($c->verb==='prune'  && $o->route==='purge') {
                    if ($this->payloads===null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
                    $this->payloads->purge($c->context,new Ref($c->id->tenant,$o->key)); $result='prune_requested';
                } else { $result='retained'; }
                if ($receipt!==null) { $c->id->tenant->assertSame($receipt->tenant); }
            }
            $items[]=$o->metadata()+['operation_result'=>$result,'receipt_ref'=>$receipt?->value];
        }
        $last=$page===[]?null:$page[count($page)-1];
        $unresolved=array_filter($items,static fn(array $item): bool=>in_array($item['operation_result'],['unknown','reconciliation_required'],true));
        $result=['status'=>!$c->apply?'dry_run':($unresolved===[]?'recorded':'unknown'),'dry_run'=>!$c->apply,'verb'=>$c->verb,'items'=>$items,
            'next_cursor'=>count($all)>count($page)&&$last!==null?$last->kind.':'.$last->key:null,
            'metrics'=>['observed'=>count($page),'unknown'=>count(array_filter($page,static fn(Observation $o): bool=>str_contains($o->reason,'unknown')))]];
        $this->authorize($c);
        return $c->verb==='inspect-run'?$result:$journal->complete($c,$result);
    }
    /** @return array<string,mixed>|null */
    public function status(Command $c): ?array { $this->authorize($c); return (new Journal($this->environment,$this->host->authority()))->status($c); }
    private function authorize(Command $c): void
    {
        $this->environment->authorize($c->context); $this->host->authority()->authorize($c);
    }
}
