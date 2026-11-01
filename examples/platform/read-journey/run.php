<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$database=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));$created=false;
try{
    SupportJourney::install($database,$schema);$created=true;$journey=new SupportJourney($database,$schema);
    $accepted=$journey->accept();$journey->drive();$delivery=$journey->delivery->deliver();
    echo json_encode(['tier'=>'local-host-e2e','transport'=>'scripted-support/v1','turn_id'=>$accepted->turnId->value,
        'run_id'=>$accepted->runId->value,'run_status'=>'completed','delivery'=>$delivery,'provider_exchanges'=>$journey->count('demo_transport_calls'),
        'actions'=>$journey->count('action_current'),'responses'=>$journey->count('responses'),'usage_facts'=>$journey->count('usage_versions')],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if($created){$database->dropSchema($schema);}}
