<?php

declare(strict_types=1);

$loader=require __DIR__.'/vendor/autoload.php';
foreach (glob(dirname(__DIR__,3).'/src/Delivery/*.php') as $source) {
    $installed=__DIR__.'/vendor/agentools/platform-loqum/src/Delivery/'.basename($source);
    if (!is_file($installed) || hash_file('sha256',$source)!==hash_file('sha256',$installed)) { throw new RuntimeException('Production delivery installation missing/stale'); }
}
foreach (['Coordinator','Ledger','Schema','VoiceConfirmation','PlayoutReceipt'] as $type) {
    if (!class_exists('AgenToolsPlatform\\Loqum\\Delivery\\'.$type)) { throw new RuntimeException('Missing production delivery type'); }
}
foreach (array_keys($loader->getPrefixesPsr4()) as $prefix) {
    if (preg_match('/Tests|Testing|TestKit|Reference|PHPUnit|PHPStan/',$prefix)) { throw new RuntimeException('Development namespace leaked'); }
}
echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted','cases'=>[['id'=>'voice-delivery.production-no-dev','status'=>'passed']],
    'count'=>1,'failures'=>0,'skips'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
