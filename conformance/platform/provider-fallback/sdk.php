<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Conformance\Providers\Proof;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Models\{CacheIntent,CacheMode,ContentPart,ContinuationRef,FinishReason,MessageRole,ModelMessage,ModelRequest,ModelUsage};
use AgenToolsPlatform\ModelAdapterTests\Fixture as F;
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'provider.generate-wire-usage'=>static function (): void {
    $f=new F(); $raw=F::message(); $raw->usage=(object)['input_tokens'=>7,'output_tokens'=>0]; $http=F::jsonHttp($raw);
    $response=$f->transport($http)->generate($f->request(tools:false),$f->deployment());
    H::check($response->finishReason===FinishReason::Completed && $response->usage->inputTokens===7 && $response->usage->outputTokens===0 && $response->usage->cacheReadTokens===null);
    H::check(count($http->requests)===1 && F::body($http->requests[0])->model==='requested-model');
 },
 'provider.stream-and-tools-proposal-only'=>static function (): void {
    $f=new F(); $stream=$f->streamed(F::streamHttp(F::streamEvents(true),3));
    H::check(!$stream['state']->isUnknown() && $stream['response']->finishReason===FinishReason::ToolProposal);
    H::check(count($stream['response']->proposals)===1 && $stream['response']->proposals[0]->providerProposalRef==='toolu-stable' && $stream['response']->usage->outputTokens===3);
 },
 'provider.structured-result'=>static function (): void {
    $f=new F(); $raw=F::message(text:'{"note_id":"n1"}'); $http=F::jsonHttp($raw);
    $response=$f->transport($http)->generate($f->request(tools:false,structured:true),$f->deployment());
    H::check($response->structured!==null && isset(F::body($http->requests[0])->output_config));
 },
 'provider.missing-terminal-is-partial'=>static function (): void {
    $f=new F(); $events=F::streamEvents(); array_pop($events); $r=$f->streamed(F::streamHttp($events));
    H::check($r['state']->isUnknown() && $r['response']->partial && $r['response']->finishReason===FinishReason::Unknown && $r['response']->usage->cacheWriteTokens===null);
 },
 'provider.cancel-and-errors'=>static function (): void {
    $f=new F(); $f->cancel->requested=true; $http=F::jsonHttp(F::message());
    H::rejects(fn ()=>$f->transport($http)->generate($f->request(),$f->deployment()),ContractViolation::class); H::check(count($http->requests)===0);
    $f=new F(); $http=F::jsonHttp(F::message(text:'secret raw body'),503); $r=$f->transport($http)->generate($f->request(),$f->deployment());
    H::check($r->finishReason===FinishReason::Unknown && $r->error!==null && $r->parts===[] && $r->usage->inputTokens===null && count($http->requests)===1);
 },
 'provider.optional-cache-continuation-attachments'=>static function (): void {
    foreach (['cache','continuation','attachments'] as $kind) {
        $f=new F(); $r=$f->request(tools:false); $http=F::jsonHttp(F::message());
        $request=new ModelRequest($r->invocationId,$kind==='attachments'?[new ModelMessage(MessageRole::User,[ContentPart::reference(F::ref('approved-attachment'))])]:$r->messages,
            $r->contextSnapshotRef,$r->contextSnapshotHash,$r->credentialRef,$r->deadline,
            cache:$kind==='cache'?new CacheIntent(CacheMode::Prefer,F::ref('cache-key'),60):new CacheIntent(),
            continuation:$kind==='continuation'?new ContinuationRef(F::ref('encrypted-state'),'anthropic','account-A','eu',$r->deadline):null);
        H::rejects(fn ()=>$f->transport($http)->generate($request,$f->deployment()),ContractViolation::class); H::check(count($http->requests)===0 && $f->keys->calls===0);
    }
 },
]);
