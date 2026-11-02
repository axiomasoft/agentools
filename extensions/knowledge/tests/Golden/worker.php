<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Examples\Knowledge\KnowledgeJourney;
use AgenToolsPlatform\Conformance\Knowledge\ScriptedGold;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$db=new IsolatedDatabase();$schema=$argv[2];$scenario=$argv[3];$base=dirname(__DIR__,4).'/conformance/platform/knowledge/fixtures/';
$j=new KnowledgeJourney($db,$schema,$base.$scenario.'.json',new ScriptedGold($base.'gold.json',$scenario),'crash');$j->ingest();$j->prepare();
$j->host->barrier=function(string $name): void {
    if ($name==='knowledge-provider') { fwrite(STDOUT,"PROVIDER_SAVED\n");fflush(STDOUT);fgets(STDIN); }
};
fwrite(STDOUT,"READY\n");fflush(STDOUT);fgets(STDIN);$j->run();
