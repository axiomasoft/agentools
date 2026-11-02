<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Knowledge\{Decision, EmbeddingSpace};
use AgenToolsPlatform\Knowledge\Tests\Fixture;

$root = dirname(__DIR__, 3) . '/fixtures/platform/v1/knowledge';
$f = new Fixture(); $release = $f->release();
$space = new EmbeddingSpace('provider:a', 'model:a', 'r1', 3, 'unit', 'index:1');
$other = new EmbeddingSpace('provider:a', 'model:b', 'r1', 3, 'unit', 'index:1');
$documents = [
    'release.json' => ['evidence_kind'=>'host_contract_harness', 'release'=>$release->projection(), 'hash'=>$release->hash()],
    'material.json' => ['evidence_kind'=>'host_contract_harness', 'material'=>get_object_vars($f->material)],
    'evidence.json' => ['evidence_kind'=>'design_example', 'run_ref'=>'run:example', 'tenant'=>$f->scope->tenant,
        'release_ref'=>['corpus'=>$release->corpus, 'id'=>$release->id, 'hash'=>$release->hash()],
        'fragment'=>$release->projection()['artifacts'][1], 'disclosure_access_check'=>'required_current_owner_check',
        'retrieval_implemented'=>false],
    'negative.json' => ['evidence_kind'=>'host_contract_harness', 'cases'=>[
        ['id'=>'cross-tenant', 'scope'=>['tenant'=>'tenant:b'], 'material_tenant'=>'tenant:a', 'expected'=>'denied_before_source'],
        ['id'=>'revoke', 'decision'=>Decision::Denied->value, 'pinned_release'=>$release->id, 'expected'=>'denied_current_owner'],
        ['id'=>'space-mismatch', 'left'=>get_object_vars($space), 'right'=>get_object_vars($other), 'expected'=>'incompatible_despite_equal_dimensions'],
    ]],
];
foreach ($documents as $name=>$document) {
    file_put_contents($root . '/' . $name, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
}
