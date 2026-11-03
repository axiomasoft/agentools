<?php

declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
$ok=class_exists(\AgenToolsPlatform\ChatomBridge\PostgresDelivery::class)
    && class_exists(\AgenToolsPlatform\ChatomBridge\BoundedHistory::class)
    && !class_exists('AgenToolsPlatform\\ChatomBridge\\Tests\\Fixture')
    && !class_exists('AgenToolsPlatform\\Examples\\Chatom\\ScriptedDestination')
    && !class_exists('AgenToolsPlatform\\Testing\\Providers\\ScriptedTransport')
    && !class_exists('AgenTools\\Reference\\Adapters\\DeliveryCollector');
foreach (glob(dirname(__DIR__,2).'/src/*.php') ?: [] as $source) {
    $installed=__DIR__.'/vendor/agentools/platform-chatom-bridge/src/'.basename($source);
    $ok=$ok && is_file($installed) && hash_file('sha256',$source)===hash_file('sha256',$installed);
}
echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted',
    'cases'=>[['id'=>'chatom.production-no-dev','status'=>$ok?'passed':'failed']],
    'count'=>1,'failures'=>$ok?0:1,'skips'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
exit($ok?0:1);
