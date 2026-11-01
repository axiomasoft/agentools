<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Security\{BoundaryStage,EntryPoint,GeneratedChunk,LogCode,LogProjection,TraceLinks};
use AgenToolsPlatform\Tests\Security\Fixture;

$f=new Fixture(true);$f->policy->outputSensitive=true;$stream=$f->disclosure();
$stream->push(new GeneratedChunk($f->core->ref('invocation:guard'),$f->core->ref('response:1'),1,'synthetic report',false,false));
$before=$stream->statistics();$payload=$stream->push(new GeneratedChunk($f->core->ref('invocation:guard'),$f->core->ref('response:1'),2,' complete',true,true));
if ($payload===null) { throw new RuntimeException('Expected checked payload'); }
$checked=$stream->statistics();$receipt=$stream->deliver($payload->ref,$f->policy);
$decision=$f->guard->check($f->core->context,$f->snapshot,EntryPoint::Stream,BoundaryStage::Disclosure);
$examples=['buffered-report.json'=>['before_full_check'=>$before,'after_full_check'=>$checked,'after_delivery'=>$stream->statistics(),'receipt'=>$receipt->toArray()],
    'disclosure-policy.json'=>$f->policy->policy($f->core->context,$f->snapshot,$f->core->ref('response:1'))->toArray(),
    'guard-decision.json'=>$decision->toArray(),
    'redacted-log.json'=>LogProjection::record($f->core->context,LogCode::Allowed,new TraceLinks(snapshot:$f->snapshot->ref(),decision:$decision->ref),['nested'=>['payload'=>'synthetic private']])];
$root=dirname(__DIR__,4).'/fixtures/platform/v1/security';
foreach ($examples as $name=>$value) { if (file_put_contents($root.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")===false) { throw new RuntimeException('Security fixture generation failed'); }echo $name.PHP_EOL; }
