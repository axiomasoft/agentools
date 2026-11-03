<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Examples\Voice\Journey;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$db=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));
Journey::install($db,$schema);
try {
    $j=new Journey($db,$schema);$j->accept(['kind'=>'final','utterance_id'=>'ut-1']);$segments=$j->output();
    $j->delivery->send($segments[0]);foreach ($segments as $s) { $j->delivery->display($s); }
    $j->delivery->interrupt($j->response??throw new RuntimeException('No response'));
    echo json_encode(['evidence_kind'=>'voice_journey_contract_harness','tier'=>'local-host-e2e',
        'turns'=>$j->count('turns'),'runs'=>$j->count('runs'),'model_calls'=>$j->modelCalls(),
        'generated_segments'=>count($segments),'confirmed_segments'=>count($j->delivery->history($j->response)),
        'displayed_segments'=>$j->count('voice_demo_display'),'heard_summary'=>$j->heard(),
        'tuple'=>$j->tuple(),
        'live_provider'=>'not_executed','production_readiness'=>'not_claimed'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally { $db->dropSchema($schema); }
