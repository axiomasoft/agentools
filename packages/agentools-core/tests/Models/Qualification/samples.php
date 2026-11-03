<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Tests\Qualification\Fixture;
$fixture = new Fixture(); $stable = $fixture->snapshot(); $candidate = $fixture->snapshot('new',$fixture->f->entry(['id'=>'canary']));
$canary = $fixture->rollout($stable->ref,$candidate->ref); $rollback = $fixture->rollout($stable->ref,null,'rollback',rollbackOf:$canary->ref);
$missing = $fixture->snapshot('missing',executed:['A']);
$samples = [
    'snapshot-technical.json'=>$stable->toArray(),
    'snapshot-domain-insufficient.json'=>$fixture->snapshot('domain',policy:$fixture->policy('educational'))->toArray(),
    'negative-insufficient-evidence.json'=>['schema_version'=>'qualification-negative-sample/v1','synthetic_sample'=>true,
        'evidence_kind'=>'contract-scripted','required_case_ids'=>['A','B'],'executed_case_ids'=>['A'],
        'suite_status'=>$missing->decision,'exit_code'=>$missing->evidence->exitCode($missing->policy),'snapshot'=>$missing->toArray()],
    'policy-canary.json'=>$canary->toArray(),
    'policy-rollback.json'=>$rollback->toArray(),
];
foreach ($samples as $name => $sample) {
    if (file_put_contents(dirname(__DIR__,5).'/fixtures/platform/v1/qualification/'.$name,json_encode($sample,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL) === false) {
        throw new RuntimeException('Qualification sample could not be written');
    }
}
