<?php

declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\ChatomBridge\{BoundedHistory, Receipt};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, IntegrityConflict, Outcome, PayloadHash, Ref, SessionId, TenantId};
use AgenToolsPlatform\Core\Context\History\{MessageRef, SourceStatus};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Examples\Chatom\HostHistory;
use AgenToolsPlatform\Postgres\Delivery\DeliverySubmission;
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter};

function host(string $purpose='support.read'):array {
    $owner=new HostHistory(new TenantId('tenant-support'),new CanonicalHasher(new JcsAdapter()));
    $context=ExecutionContext::fromHost($owner,new SessionId($owner->tenant,'conversation'),$purpose,new Correlation('trace','request'));
    return [$owner,$context,new BoundedHistory($owner,$owner,$owner),new Ref($owner->tenant,'conversation')];
}
H::run([
 'chatom.history-support-editor'=>static function():void {
    foreach(['support.read','editor.read'] as $purpose) {
        [$owner,$context,$history,$branch]=host($purpose);
        $page=$history->recent($context,$branch,0,2);H::check(count($page->messages)===2 && $page->hasMore);
        H::check($page->messages[0]->source->ref->value==='chatom:message:1' && $page->messages[1]->source->version==='revision-1');
        H::check($history->read($context,$page->messages[0]->source)->message?->text===$page->messages[0]->text);
        $manifest=$history->manifest($context,$branch,3);H::check($manifest->complete && count($manifest->messages)===3);
    }
 },
 'chatom.history-bounds-order-tenant'=>static function():void {
    [$owner,$context,$history,$branch]=host();
    H::rejects(fn()=>$history->recent($context,$branch,0,33),ContractViolation::class);
    H::rejects(fn()=>$history->recent($context,$branch,-1,1),ContractViolation::class);
    H::rejects(fn()=>$history->recent($context,new Ref(new TenantId('tenant-b'),'conversation'),0,1),ContractViolation::class);
    $owner->reverse=true;H::rejects(fn()=>$history->recent($context,$branch,0,2),ContractViolation::class);
    $owner->reverse=false;$owner->associative=true;
    H::rejects(fn()=>$history->recent($context,$branch,0,1),ContractViolation::class);
 },
 'chatom.history-revoke-expiry-exact-version'=>static function():void {
    [$owner,$context,$history,$branch]=host();$message=$history->recent($context,$branch,0,1)->messages[0]->source;
    $wrong=new MessageRef($message->ref,$message->branch,$message->turn,'changed',$message->hash);
    H::rejects(fn()=>$history->read($context,$wrong),ContractViolation::class);
    $owner->expired=true;H::rejects(fn()=>$history->read($context,$message),ContractViolation::class);
    $owner->expired=false;$owner->duringRead=static function()use($owner):void{$owner->allowed=false;};
    H::rejects(fn()=>$history->recent($context,$branch,0,1),ContractViolation::class);
 },
 'chatom.history-unavailable-not-empty-success'=>static function():void {
    [$owner,$context,$history,$branch]=host();$message=$history->recent($context,$branch,0,1)->messages[0]->source;
    $owner->unavailable=true;
    H::check($history->recent($context,$branch,0,1)->status===SourceStatus::Unavailable);
    H::check($history->read($context,$message)->message===null && $history->read($context,$message)->status===SourceStatus::Unavailable);
    H::check(!$history->manifest($context,$branch,3)->complete);
 },
 'chatom.receipt-unknown-and-binding'=>static function():void {
    $t=new TenantId('tenant-a');$ref=static fn(string $id):Ref=>new Ref($t,$id);$hash=new PayloadHash('sha-256:'.str_repeat('a',64));
    $intent=new DeliverySubmission('saved-native-key',new \AgenToolsPlatform\Core\Common\RunId($t,'run'),$ref('response'),1,$ref('payload'),$hash,$ref('destination'),$ref('source'),1,'policy-1',$hash);
    (new Receipt($ref('destination'),$intent->key,$hash,Outcome::Unknown,null))->assertSubmission($intent);
    H::rejects(fn()=>new Receipt($ref('destination'),$intent->key,$hash,Outcome::Succeeded,null),InvalidArgumentException::class);
    H::rejects(fn()=>new Receipt($ref('destination'),$intent->key,$hash,Outcome::Unknown,$ref('message')),InvalidArgumentException::class);
    H::rejects(fn()=>(new Receipt($ref('destination'),$intent->key,new PayloadHash('sha-256:'.str_repeat('b',64)),Outcome::Unknown,null))->assertSubmission($intent),IntegrityConflict::class);
    H::rejects(fn()=>(new Receipt($ref('other'),$intent->key,$hash,Outcome::Succeeded,$ref('message')))->assertSubmission($intent),ContractViolation::class);
 },
]);
