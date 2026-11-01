<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,UtcInstant};
use AgenToolsPlatform\Core\Context\{Classification,TrustClass};
use AgenToolsPlatform\Core\Context\History\{SourceStatus,Trigger,TriggerPolicy,WorkingStateEditor};
use AgenToolsPlatform\Tests\Context\History\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'CM-014'=>static function(): void {
        $f=new Fixture();$summary=$f->summary();$current=$f->source->messages['message:11']->source;
        $selection=$f->selector()->select($f->core->context,$f->branch,$f->state,$summary,[$current],$f->core->now);
        $recent=array_values(array_filter($selection->blocks,static fn($b): bool=>$b->kind==='recent'));
        H::check(count($recent)===1 && $recent[0]->source->value==='message:11' && $recent[0]->mandatory);
        H::check($summary->sources->throughTurn===10 && count($summary->sources->messages)===10);
        $compiled=$f->core->compiler->finalize($f->core->plan($selection->blocks),$f->core->target(),$f->core,$f->core->now,$f->core);
        H::check(str_contains($compiled->rendered,'exact turn 11') && !str_contains($compiled->rendered,'exact turn 10'));
    },
    'CM-017'=>static function(): void {
        $f=new Fixture();$first=$f->summary();$f->source->statuses['message:3']=SourceStatus::Unavailable;
        $result=$f->builder()->rebuild($f->core->context,$f->branch,11,1,$f->state,$f->core->now);
        H::check($result->status===SourceStatus::Unavailable && !$result->revalidated && $result->summary===null);
        H::rejects(static fn()=> new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Unavailable,$first,false),InvalidArgumentException::class);
        H::check($f->store->head($f->core->context,$f->branch)?->hash->value===$first->hash->value && count($f->extractor->inputs)===1);
        $f->source->pageStatus=SourceStatus::Unavailable;
        $degraded=$f->selector(2)->select($f->core->context,$f->branch,$f->state,null,[],$f->core->now);
        H::check($degraded->status===SourceStatus::Unavailable && count($degraded->blocks)===1 && $degraded->blocks[0]->mandatory);
    },
    'V2ALL-HISTORY-POSITIVE'=>static function(): void {
        $f=new Fixture();$summary=$f->summary();$first=$summary->toArray();
        H::check($summary->classification===Classification::Private && $first['trust']==='untrusted');
        H::check($summary->fields->promises[0]->value==='promise:1' && $summary->fields->actions[0]->value==='action:1');
        $selected=$f->selector()->select($f->core->context,$f->branch,$f->state,$summary,[],$f->core->now);
        foreach ($selected->blocks as $block) { H::check($block->trustTag===TrustClass::Untrusted); }
        $state=array_values(array_filter($selected->blocks,static fn($b): bool=>$b->kind==='working_state'))[0];
        foreach (['goal','step','question','tool:result','ui:active','promise:1','action:1'] as $ref) { H::check(str_contains($state->modelView,$ref)); }
        H::check(!str_contains(json_encode($first,JSON_THROW_ON_ERROR),'exact turn') && !str_contains(json_encode($f->state->toArray(),JSON_THROW_ON_ERROR),'exact turn'));
        $exact=$f->selector()->select($f->core->context,$f->branch,$f->state,null,[],$f->core->now);
        $plan=$f->core->plan($exact->blocks);$ordered=[];
        foreach ($plan->blocks as $block) { if ($block->kind==='recent') { $ordered[]=$block->source->value; } }
        H::check($ordered===array_map(static fn(int $i): string=>'message:'.$i,range(1,11)));

    },
    'V2ALL-HISTORY-NEGATIVE'=>static function(): void {
        $f=new Fixture();$summary=$f->summary();$overlap=$f->selector()->select($f->core->context,$f->branch,$f->state,$summary,[$f->source->messages['message:1']->source],$f->core->now);
        H::check($overlap->exclusions[0]['reason']==='overlaps_mandatory_exact');
        H::check(count(array_filter($overlap->blocks,static fn($b): bool=>$b->kind==='summary'))===0);
        $f->host->denyState=true;$editor=new WorkingStateEditor($f->store,$f->host);
        H::rejects(static fn()=> $editor->apply($f->core->context,$f->state->value,1,$f->core->now,$f->state->expiresAt,'working'),ContractViolation::class,ErrorCode::AccessDenied);
        H::rejects(static fn()=> (new ReflectionMethod($editor,'apply'))->invoke($editor,$f->core->context,['model'=>'replace state'],1,$f->core->now,$f->state->expiresAt,'working'),TypeError::class);
        H::check($f->store->state($f->core->context,$f->branch)?->version===1);
        $f->extractor->text='corrupt cached summary';
        H::rejects(static fn()=> $f->selector()->select($f->core->context,$f->branch,$f->state,$summary,[],$f->core->now),\AgenToolsPlatform\Core\Common\IntegrityConflict::class);
    },
    'V2ALL-HISTORY-FAULT'=>static function(): void {
        $f=new Fixture();$f->extractor->afterExtract=static function() use($f): void { $f->source->statuses['message:2']=SourceStatus::Revoked; };
        $result=$f->builder()->rebuild($f->core->context,$f->branch,10,0,$f->state,$f->core->now);
        H::check(!$result->revalidated && $result->status===SourceStatus::Revoked && $f->store->versions===[]);
        $f=new Fixture();$f->extractor->afterExtract=static function() use($f): void { $f->host->consent='consent:v2'; };
        H::rejects(static fn()=> $f->builder()->rebuild($f->core->context,$f->branch,10,0,$f->state,$f->core->now),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->store->versions===[] && count($f->store->tombstones)===10);
        $f=new Fixture();$f->extractor->afterExtract=static function() use($f): void {
            $text='new exact message';$ref=new \AgenToolsPlatform\Core\Context\History\MessageRef($f->core->ref('message:10b'),$f->branch,10,'v1',$f->core->hasher->hash($f->core->tenant,'history-message',$text));
            $f->source->messages['message:10b']=new \AgenToolsPlatform\Core\Context\History\ExactMessage($ref,$f->core->ref('owner:chatom'),$text,Classification::Private,$f->state->expiresAt,$f->core->hasher);
        };
        H::rejects(static fn()=> $f->builder()->rebuild($f->core->context,$f->branch,10,0,$f->state,$f->core->now),\AgenToolsPlatform\Core\Common\IntegrityConflict::class);
        H::check($f->store->versions===[]);
        $f=new Fixture();$old=$f->source->messages['message:1'];$expiry=new UtcInstant('2026-11-01T12:00:00.123457Z');
        $f->source->messages['message:1']=new \AgenToolsPlatform\Core\Context\History\ExactMessage($old->source,$old->owner,$old->text,$old->classification,$expiry,$f->core->hasher);
        $summary=$f->summary();H::check($summary->expiresAt->value===$expiry->value);
        $selected=$f->selector()->select($f->core->context,$f->branch,$f->state,$summary,[],$expiry);
        H::check($selected->exclusions[0]['reason']==='expired');

    },
    'history.working-cas'=>static function(): void {
        $f=new Fixture();$editor=new WorkingStateEditor($f->store,$f->host);
        $next=$editor->apply($f->core->context,$f->state->value,1,$f->core->now,$f->state->expiresAt,'working:24h');
        H::check($next->version===2 && $next->value->promises===$f->state->value->promises);
        H::rejects(static fn()=> $editor->apply($f->core->context,$f->state->value,1,$f->core->now,$f->state->expiresAt,'working'),ContractViolation::class,ErrorCode::OptimisticConflict);
        H::rejects(static fn()=> $f->selector()->select($f->core->context,$f->branch,$f->state,null,[],new UtcInstant('2026-11-02T00:00:00Z')),ContractViolation::class,ErrorCode::StaleContext);
    },
    'history.trigger-source-rebuild'=>static function(): void {
        $f=new Fixture();$first=$f->summary();$policy=new TriggerPolicy(500,3);
        H::check($policy->due(499,false,null,0)===null && $policy->due(500,false,null,0)===Trigger::Threshold);
        H::check($policy->due(0,true,null,0)===Trigger::Segment && $policy->due(0,false,Trigger::Model,0)===Trigger::Model);
        H::check($policy->due(0,false,null,3)===Trigger::PeriodicRebuild);
        H::rejects(static fn()=> $policy->due(-1,false,null,0),InvalidArgumentException::class);
        H::rejects(static fn()=> $f->builder()->rebuild($f->core->context,$f->branch,9,1,$f->state,$f->core->now),ContractViolation::class,ErrorCode::OptimisticConflict);
        $second=$f->builder()->rebuild($f->core->context,$f->branch,11,1,$f->state,$f->core->now)->summary;
        H::check($second!==null && $second->version===2 && $second->sources->throughTurn===11 && $second->hash->value!==$first->hash->value);
        H::check(count($f->extractor->inputs[1])===11 && $f->extractor->inputs[1][0]==='exact turn 1' && !str_contains(implode('|',$f->extractor->inputs[1]),'Derived background'));
        H::check($first->toArray()===$f->store->versions[0]->toArray());
    },
    'history.bounded-unavailable'=>static function(): void {
        $f=new Fixture();$selected=$f->selector(2)->select($f->core->context,$f->branch,$f->state,null,[],$f->core->now);
        H::check(count($selected->blocks)===3 && $selected->hasMore);
        $f->source->overflow=true;
        H::rejects(static fn()=> $f->selector(2)->select($f->core->context,$f->branch,$f->state,null,[],$f->core->now),ContractViolation::class,ErrorCode::PolicyConflict);
        $f->source->statuses['message:11']=SourceStatus::Unavailable;
        H::rejects(static fn()=> $f->selector()->select($f->core->context,$f->branch,$f->state,null,[$f->source->messages['message:11']->source],$f->core->now),ContractViolation::class,ErrorCode::CapabilityUnavailable);
    },
]);
