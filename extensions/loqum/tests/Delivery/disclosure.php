<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once dirname(__DIR__,4).'/packages/agentools-core/tests/Security/bootstrap.php';

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Security\{DisclosureMode, EntryPoint, GeneratedChunk};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Security\Fixture;

H::run(['voice-delivery.core-disclosure'=>static function():void {
    $f=new Fixture(true);$stream=$f->disclosure(EntryPoint::Voice);
    H::check($stream->push(new GeneratedChunk($f->core->ref('invocation:guard'),$f->core->ref('response:1'),1,'private ',true,false))===null);
    H::check($stream->statistics()['delivered_bytes']===0);
    $checked=$stream->push(new GeneratedChunk($f->core->ref('invocation:guard'),$f->core->ref('response:1'),2,'report',true,true));
    H::check($checked!==null && $checked->prechecked && $checked->bytes==='private report');
    H::check($stream->statistics()['confirmed_prefix_bytes']===0);
    $g=new Fixture();$g->policy->mode=DisclosureMode::CheckedSegments;$segments=$g->disclosure(EntryPoint::Voice);
    H::check($segments->push(new GeneratedChunk($g->core->ref('invocation:guard'),$g->core->ref('response:1'),1,'unclosed',false,false))===null);
    $g->policy->inspection=\AgenToolsPlatform\Core\Security\AccessState::Deny;
    H::rejects(fn()=>$segments->push(new GeneratedChunk($g->core->ref('invocation:guard'),$g->core->ref('response:1'),2,' segment',true,true)),ContractViolation::class);
}]);
