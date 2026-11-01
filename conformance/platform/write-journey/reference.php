<?php

declare(strict_types=1);

require_once __DIR__.'/PortableReference.php';
$raw=file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/recovery/portable.json');
if ($raw===false) { throw new RuntimeException('Required portable fixtures missing'); }
/** @var array{cases:list<array<string,mixed>>} $data */
$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); $rows=[];
foreach ($data['cases'] as $case) { $rows[]=['id'=>$case['id'],'observed'=>\AgenToolsPlatform\Conformance\WriteJourney\PortableReference::run($case)]; }
echo json_encode(['consumer'=>'reference','tier'=>'contract-scripted','cases'=>$rows],JSON_THROW_ON_ERROR)."\n";
