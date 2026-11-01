<?php

declare(strict_types=1);

require_once __DIR__.'/PortableRecovery.php';

$rows=[];
foreach (\AgenTools\Tests\Recovery\PortableRecovery::cases() as $case) {
    $rows[]=['id'=>$case['id'],'observed'=>\AgenTools\Tests\Recovery\PortableRecovery::run($case)];
}
echo json_encode(['consumer'=>'php-sdk','tier'=>'contract-scripted','cases'=>$rows],JSON_THROW_ON_ERROR)."\n";
