<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Conformance\Providers\{Fixture,Owner,Proof};
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Models\{Deployment,EventKind,FinishReason,ModelEvent,ModelResponse};
use AgenToolsPlatform\Core\Models\Routing\{FallbackPlan,OutputContext};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Routing\Fixture as R;

Proof::run([
 'fallback.before-disclosure-discards-buffer'=>static function (): void {
    $f=new Fixture(); $f->buffer(); $plan=$f->plan(); H::check($plan->outcome==='reroute' && !$plan->retryActions);
    $f->boundary->switchTo($plan,$f->context); $f->next($plan); $chunk=$f->boundary->disclose();
    H::check($chunk->text==='new answer' && $chunk->invocation->value!==$f->original->value && $chunk->deployment->value==='fallback-model' && $chunk->offset===0);
    H::check($f->boundary->history()[0]['status']==='undisclosed_discarded');
 },
 'fallback.after-disclosure-requires-segment'=>static function (): void {
    $f=new Fixture(); $f->buffer('model A'); $first=$f->boundary->disclose(); $interrupted=$f->plan(); H::check($interrupted->outcome==='interrupted');
    H::rejects(fn ()=>$f->boundary->switchTo($interrupted,$f->context),LogicException::class);
    $plan=$f->plan(true,true); $f->boundary->switchTo($plan,$f->context); $f->next($plan,'model B'); $second=$f->boundary->disclose();
    H::check($first->segment->value!==$second->segment->value && $first->invocation->value!==$second->invocation->value && $first->requestedModel!==$second->requestedModel);
    H::check($first->text==='model A' && $second->text==='model B' && $second->offset===0 && $f->boundary->history()[0]['status']==='interrupted');
 },
 'fallback.segment-forgery-denied'=>static function (): void {
    $f=new Fixture(); $f->buffer(); $f->boundary->disclose(); $plan=$f->plan(true,true);
    $forged=new FallbackPlan('reroute',$plan->origin,$plan->route,$plan->invocation);
    H::rejects(fn ()=>$f->boundary->switchTo($forged,$f->context),LogicException::class);
    $forged=new FallbackPlan('new_segment',$plan->origin,$plan->route,$plan->invocation,R::ref('segment-1'));
    H::rejects(fn ()=>$f->boundary->switchTo($forged,$f->context),LogicException::class);
 },
 'fallback.context-and-actions-persist'=>static function (): void {
    $f=new Fixture(); $plan=$f->plan();
    $changed=new OutputContext($f->context->bundle,$f->context->goal,R::ref('new-state'),$f->context->businessOperation,$f->context->actions);
    H::rejects(fn ()=>$f->boundary->switchTo($plan,$changed),\AgenToolsPlatform\Core\Common\ContractViolation::class,\AgenToolsPlatform\Core\Common\ErrorCode::IntegrityConflict);
    $f->boundary->switchTo($plan,$f->context); H::check($plan->origin->actions===$f->context->actions && !$plan->retryActions && $plan->origin->goal->value==='goal' && $plan->origin->bundle->value==='bundle');
 },
 'fallback.unknown-effect-before-continuation'=>static function (): void {
    $f=new Fixture(); $owner=new Owner(); H::check($f->plan(unknownEffect:R::ref('owner-key'))->outcome==='reconcile_required');
    H::check(!$f->boundary->reconcile($owner)); H::check($f->plan()->outcome==='reconcile_required');
    $owner->wrongOperation=true; $owner->outcome='success'; H::rejects(fn ()=>$f->boundary->reconcile($owner),\AgenToolsPlatform\Core\Common\ContractViolation::class,\AgenToolsPlatform\Core\Common\ErrorCode::IntegrityConflict);
    $owner->wrongOperation=false; H::check($f->boundary->reconcile($owner)); $plan=$f->plan(); $f->boundary->switchTo($plan,$f->context);
    H::check($owner->lookups===['owner-key','owner-key','owner-key'] && $owner->effectCount===1 && !$plan->retryActions);
 },
 'fallback.policy-before-cost-no-escape'=>static function (): void {
    $f=new Fixture(); $forbidden=$f->routing->entry(['id'=>'cheap-forbidden','region'=>'EU','cost_minor'=>0,'pricing_version'=>'synthetic-only',
        'pricing_evidence'=>R::read('catalog.json')['model_evidence']]);
    $f->routing->states=[$f->routing->qualified($f->primary),$f->routing->qualified($forbidden)];
    H::check($f->plan()->outcome==='non_ai_text' && $f->routing->calls===0);
 },
 'fallback.source-mismatch-and-late-packets'=>static function (): void {
    $changed=new Fixture(); $changed->buffer();
    $differentRequested=new ModelResponse($changed->original,$changed->alternate->deployment,null,'provider-ref',FinishReason::Completed);
    H::rejects(fn ()=>$changed->boundary->observe(new ModelEvent($changed->original,3,EventKind::Terminal,response:$differentRequested)),\AgenToolsPlatform\Core\Common\ContractViolation::class);
    H::rejects(fn ()=>$changed->boundary->disclose(),LogicException::class);
    $f=new Fixture(); $f->buffer(); $d=$f->primary->deployment;
    $wrong=new Deployment($d->ref,$d->provider,$d->account,$d->region,'other-source-model',$d->capabilities);
    $response=new ModelResponse($f->original,$d,$wrong,'provider-ref',FinishReason::Completed);
    H::rejects(fn ()=>$f->boundary->observe(new ModelEvent($f->original,3,EventKind::Terminal,response:$response)),IntegrityConflict::class);
    H::rejects(fn ()=>$f->boundary->disclose(),LogicException::class);
    $plan=$f->plan(); $f->boundary->switchTo($plan,$f->context);
    H::rejects(fn ()=>$f->boundary->observe(new ModelEvent($f->original,1,EventKind::Started)),\AgenToolsPlatform\Core\Common\ContractViolation::class);
 },
]);
