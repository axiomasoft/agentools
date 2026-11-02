<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__,3).'/packages/agentools-postgres/tests/IsolatedDatabase.php';
require_once dirname(__DIR__,3).'/conformance/platform/knowledge/ScriptedGold.php';

use AgenToolsPlatform\Conformance\Knowledge\ScriptedGold;
use AgenToolsPlatform\Examples\Knowledge\KnowledgeJourney;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$scenario=$argv[1] ?? 'support';
if (!in_array($scenario,['support','education'],true)) { throw new InvalidArgumentException('Synthetic support or education scenario required'); }
$database=new IsolatedDatabase();$schema='p3_'.bin2hex(random_bytes(12));
KnowledgeJourney::install($database,$schema);
try {
    $base=dirname(__DIR__,3).'/conformance/platform/knowledge/fixtures/';
    $journey=new KnowledgeJourney($database,$schema,$base.$scenario.'.json',new ScriptedGold($base.'gold.json',$scenario));
    $release=$journey->ingest();$journey->prepare();$stage=$journey->run();$delivery=$journey->deliver();
    echo json_encode(['scenario'=>$scenario,'stage'=>$stage,'delivery'=>$delivery,'fragments'=>$journey->selectedEvidence(),
        'release_hash'=>$release->hash(),'qualification'=>$journey->validator->qualification(),
        'tier'=>'local-host-e2e','transport'=>'scripted','extractor'=>'scripted','index'=>'isolated-postgresql-tsvector-gin'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
} finally { $database->dropSchema($schema); }
