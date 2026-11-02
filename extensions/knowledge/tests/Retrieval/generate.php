<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Knowledge\Retrieval\OwnerViews;
use AgenToolsPlatform\Knowledge\Tests\Retrieval\Fixture;

$f = new Fixture(); $pin = $f->add(); $q = $f->query([$pin]); $service = $f->service();
$result = $service->disclose($q,$service->retrieve($q));
$root = dirname(__DIR__,4) . '/fixtures/platform/v1/retrieval';
$documents = [
    'evidence.json' => ['evidence_kind'=>'host_contract_harness','query_hash'=>$q->queryHash,
        'result'=>$result,'tool_result'=>$result->toolView(new OwnerViews('alpha','owner:raw-table','owner:user','owner:audit'))],
    'negative.json' => ['evidence_kind'=>'host_contract_harness','cases'=>[
        ['id'=>'cross-tenant','expected'=>'denied_before_candidates'],['id'=>'teacher-only','expected'=>'denied_before_candidates'],
        ['id'=>'revoked','expected'=>'denied_even_cached'],['id'=>'space-mismatch','expected'=>'SPACE_MISMATCH'],
        ['id'=>'unavailable','expected'=>'unavailable_without_citations'],
    ]],
];
foreach ($documents as $name=>$value) {
    file_put_contents($root.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}
