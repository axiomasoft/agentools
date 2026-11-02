<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Golden;

use AgenToolsPlatform\Examples\Knowledge\KnowledgeJourney;
use AgenToolsPlatform\Conformance\Knowledge\ScriptedGold;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

trait Fixture
{
    private IsolatedDatabase $database;
    private string $schema;
    protected function setUp(): void
    {
        $this->database=new IsolatedDatabase();$this->schema='p3_'.bin2hex(random_bytes(12));
        KnowledgeJourney::install($this->database,$this->schema);
    }
    protected function tearDown(): void { $this->database->dropSchema($this->schema); }
    private function journey(string $scenario='support',string $key='first',bool $gold=true): KnowledgeJourney
    {
        $base=dirname(__DIR__,4).'/conformance/platform/knowledge/fixtures/';
        return new KnowledgeJourney($this->database,$this->schema,$base.$scenario.'.json',$gold?new ScriptedGold($base.'gold.json',$scenario):null,$key);
    }
    private function countRows(string $table): int
    { return (int)$this->database->pdo->query('SELECT count(*) FROM "'.$this->schema.'".'.$table)->fetchColumn(); }
    private function response(KnowledgeJourney $j): array
    {
        $row=$j->host->db->row('SELECT response_json FROM '.$j->host->db->table('knowledge_demo_calls').' WHERE tenant=:tenant AND invocation_id=:id',
            ['tenant'=>$j->scope->tenant,'id'=>$j->invocation->request->invocationId->value]);
        return json_decode($row['response_json'],true,64,JSON_THROW_ON_ERROR);
    }
    private function observation(string $name,array $data): void
    {
        $path=getenv('AGENTOOLS_KNOWLEDGE_OBSERVATIONS');
        if (!is_string($path) || $path==='') { throw new \RuntimeException('Observation path missing'); }
        file_put_contents($path,json_encode([$name=>$data],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
}
