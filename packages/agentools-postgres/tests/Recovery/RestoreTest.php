<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Recovery;

use AgenToolsPlatform\Conformance\WriteJourney\Journey;
use AgenToolsPlatform\Core\Usage\{UsageEvent,UsageFold};
use AgenToolsPlatform\Postgres\Actions\{ActionRepository,Projection};
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Usage\{BacklogPolicy,Repository,Schema};
use AgenToolsPlatform\Tests\Usage\Fixture as UsageFixture;
use PHPUnit\Framework\TestCase;

final class RestoreTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    protected function setUp(): void
    {
        $this->database=new IsolatedDatabase(); $this->schema='p3_'.bin2hex(random_bytes(12));
        Journey::install($this->database,$this->schema);
        Schema::install($this->database->pdo,$this->schema);
    }
    protected function tearDown(): void
    {
        if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
        $this->database->dropSchema($this->schema);
    }
    /** @param list<string> $command */
    private function command(array $command): void
    {
        $env=$_ENV; foreach (['PATH','HOME','LD_LIBRARY_PATH'] as $key) { $value=getenv($key); if (is_string($value)) { $env[$key]=$value; } }
        $env['PGPASSWORD']=(string)getenv('AGENTOOLS_TEST_PG_PASSWORD');
        $p=proc_open($command,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);
        if (!is_resource($p)) { throw new \RuntimeException('Could not start PostgreSQL backup tool'); }
        fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($p),'PostgreSQL backup/restore failed: '.$stderr.$stdout);
    }
    /** @return array<string,string> */
    private function facts(Journey $j): array
    {
        $facts=[];
        foreach (['turns','runs','run_steps','invocation_intents','invocation_results','proposal_map','action_submissions',
            'agentools_events','action_current','effect_intents','outbox','responses','delivery_intents','delivery_controls',
            'delivery_receipts','usage_versions','accounting_usage','accounting_export','example_owner_writes','write_messages'] as $table) {
            $sql='SELECT COALESCE(json_agg(row_to_json(t) ORDER BY row_to_json(t)::text),\'[]\'::json)::text FROM '.$j->db->table($table).' t';
            $facts[$table]=hash('sha256',(string)$j->db->execute($sql)->fetchColumn());
        }
        return $facts;
    }
    public function testCleanInstallBackupRestoreAndProjectionRebuild(): void
    {
        $j=new Journey($this->database,$this->schema);
        self::assertSame('completed',$j->drive()); $j->deliver();
        $action=$j->proposal()->actionId;
        $beforeProjection=(new Projection((new Journal($j->environment))->read($action)))->digest();
        $fixture=new UsageFixture();
        $first=$fixture->event(['input_total'=>100,'cache_read'=>40],['tenant_ref'=>'tenant-a','certainty'=>'preliminary']);
        $data=$first->toArray(); $data['event_id']='restore-correction'; $data['source']['usage_version']=2;
        $data['supersedes_event_id']=$first->id->value; $data['certainty']='final'; $data['units']['output_tokens']=12;
        $correction=UsageEvent::fromArray($data,$j->hasher);
        $usage=new Repository($j->db,$j->tenant,$j->hasher,new BacklogPolicy(20,3600,3,1,1));
        $usage->append($first); $usage->append($correction);
        self::assertSame(2,$j->count('accounting_usage')); self::assertSame(2,$j->count('accounting_export'));
        $facts=$this->facts($j); $this->database->verify();
        $dsn=(string)getenv('AGENTOOLS_TEST_PG_DSN');
        if (preg_match('/\Apgsql:host=(127\.0\.0\.1|localhost|::1);port=([0-9]{1,5});dbname=(agentools_test_[a-z0-9_]+)\z/D',$dsn,$m)!==1) { throw new \RuntimeException('Unverified DSN'); }
        $dir=sys_get_temp_dir().'/agentools-recovery-'.bin2hex(random_bytes(12));
        if (!mkdir($dir,0700)) { throw new \RuntimeException('Could not create backup directory'); }
        $archive=$dir.'/backup.dump';
        $args=['-h',$m[1],'-p',$m[2],'-U',(string)getenv('AGENTOOLS_TEST_PG_USER')];
        try {
            $this->command(['pg_dump',...$args,'--format=custom','--no-owner','--no-privileges','--schema='.$this->schema,'--file='.$archive,$m[3]]);
            self::assertFileExists($archive); self::assertGreaterThan(0,filesize($archive));
            $this->database->verify(); $this->database->dropSchema($this->schema);
            $this->database->verify();
            $this->command(['pg_restore',...$args,'--no-owner','--no-privileges','--exit-on-error','--dbname='.$m[3],$archive]);
            $fresh=new Journey(new IsolatedDatabase(),$this->schema);
            self::assertSame($facts,$this->facts($fresh),'Persisted facts differ after isolated backup restore');
            $freshUsage=new Repository($fresh->db,$fresh->tenant,$fresh->hasher,new BacklogPolicy(20,3600,3,1,1));
            $fold=UsageFold::fold([$freshUsage->event($first->id),$freshUsage->event($correction->id)]);
            self::assertCount(2,$fold->history); self::assertCount(1,$fold->active);
            self::assertSame($correction->id->value,array_values($fold->active)[0]->id->value);
            $fresh->db->execute('DELETE FROM '.$fresh->db->table('action_current').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$fresh->tenant->value,'id'=>$action->value]);
            $rebuilt=(new ActionRepository($fresh->environment))->rebuild($fresh->context,$action,$beforeProjection);
            self::assertSame($beforeProjection,$rebuilt->digest());
            self::assertSame('completed',$fresh->drive()); $fresh->deliver();
            self::assertSame(1,$fresh->count('example_owner_writes')); self::assertSame(1,$fresh->count('write_messages'));
            $fresh->db->execute('UPDATE '.$fresh->db->table('example_owner_permissions').' SET allowed=false');
            try { $fresh->deliver(); self::fail('Revoked destination must not be called'); } catch (\AgenToolsPlatform\Core\Common\ContractViolation $e) { self::assertSame(1,$fresh->count('write_messages')); }
        } finally { if (is_file($archive)) { unlink($archive); } rmdir($dir); }
    }
}
