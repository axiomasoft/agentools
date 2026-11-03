<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Cache;

use AgenToolsPlatform\Core\Budget\{BudgetBoundary,AdmissionRequest};
use AgenToolsPlatform\Core\Common\{CanonicalHasher,Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Cache\{Key,ProviderResult,WarmIntent};
use AgenToolsPlatform\Core\Usage\{Producer,ProviderMapping,UsageEvent,UsageNormalizer,UsagePort,Values};

/** Uses the canonical P9.2/P9.3 ports; no local money ledger and no implicit metering producer. */
final readonly class WarmAccounting
{
    public function __construct(private BudgetBoundary $budget,private UsagePort $usage,private CanonicalHasher $hasher,private ProviderMapping $mapping) {}
    public function intent(Key $key,WarmRequest $request,UtcInstant $now): WarmIntent
    {
        $key->id->tenant->assertSame($request->operation->tenant); $key->id->tenant->assertSame($request->admission->operationKey->tenant);
        $scope=$request->admission->content; $meta=$request->usageMetadata;
        if (($scope['deployment_ref']??null)!==$key->dimensions['deployment_ref'] || ($scope['profile_hash']??null)!==$key->dimensions['profile_hash']
            || ($scope['invocation_ref']??null)!==($meta['invocation_ref']??null) || ($scope['tenant_ref']??null)!==$key->id->tenant->value
            || ($scope['business_operation_ref']??null)!==($meta['business_operation_ref']??null)) { throw new \LogicException('Warm accounting binding changed'); }
        $invocation=Values::text($meta['invocation_ref']??null); unset($meta['invocation_ref']);
        $meta['event_id']='warm:'.$request->operation->value.':v1'; $meta['certainty']='unknown';
        $meta['occurred_at']=$now->value; $meta['supersedes_event_id']=null;
        $event=(new UsageNormalizer($this->hasher))->observe($meta,$invocation,1,Producer::Transport,Producer::Transport,$this->mapping,[],null)->event
            ?? throw new \LogicException('Canonical warm producer required');
        $hash=$this->hasher->hash($key->id->tenant,'cache-warm-intent',[$key->dimensions,$request->admission->request($this->hasher)->hash->value,
            $request->expiresAt->value,$request->estimate,$request->usageMetadata,$request->operationKind]);
        return new WarmIntent($request->operation,$key,$hash,$now,$request->expiresAt,$request->estimate,json_encode($event->toArray(),JSON_THROW_ON_ERROR),
            json_encode($request->admission->content,JSON_THROW_ON_ERROR));
    }
    public function reserve(WarmRequest $request,UtcInstant $now): Ref
    {
        $reply=$this->budget->admit($request->admission,$now);
        if ($reply->status!=='admitted' || $reply->reservationRef===null) { throw new \LogicException('Warm owner admission denied/unknown'); }
        return $reply->reservationRef;
    }
    public function fact(WarmIntent $intent,ProviderResult $reply,?bool $sent=null): string
    {
        $meta=Values::object(json_decode($intent->unknownUsageJson,true,flags:JSON_THROW_ON_ERROR));
        $source=Values::object($meta['source']); $invocation=Values::text($source['invocation_id']);
        unset($meta['source'],$meta['units'],$meta['provider_amount']); $meta['certainty']=$reply->status==='created'?'final':'unknown';
        $raw=['inputTokens'=>$reply->usage->inputTokens,'outputTokens'=>$reply->usage->outputTokens,
            'cacheReadTokens'=>$reply->usage->cacheReadTokens,'cacheWriteTokens'=>$reply->usage->cacheWriteTokens,
            'amount'=>$reply->amount===null?null:['amount'=>$reply->amount,'currency'=>$reply->currency]];
        $event=(new UsageNormalizer($this->hasher))->observe($meta,$invocation,1,Producer::Transport,Producer::Transport,$this->mapping,$raw,
            $reply->status==='created'?true:$sent)->event ?? throw new \LogicException('Canonical warm event required');
        return json_encode($event->toArray(),JSON_THROW_ON_ERROR);
    }
    public function export(string $json): void
    { $this->usage->append(UsageEvent::fromArray(Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR)),$this->hasher)); }
}
