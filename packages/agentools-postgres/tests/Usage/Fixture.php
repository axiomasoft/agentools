<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Usage;

use AgenToolsPlatform\Core\Common\{Ref, TenantId};
use AgenToolsPlatform\Core\Runtime\AdvanceRun;
use AgenToolsPlatform\Core\Usage\ProviderMapping;
use AgenToolsPlatform\Postgres\Budget\Reservations;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Models\TestRig;
use AgenToolsPlatform\Postgres\Usage\{BacklogPolicy, Recorder, Repository, Schema};

final class Fixture
{
    public IsolatedDatabase $database;
    public string $schema;
    public TestRig $rig;
    public Repository $usage;
    public Owner $owner;
    public Reservations $budget;
    public Recorder $recorder;
    public function __construct(?string $schema=null, string $tenant='tenant-a', ?BacklogPolicy $policy=null)
    {
        $this->database=new IsolatedDatabase(); $this->schema=$schema ?? 'p3_'.bin2hex(random_bytes(12));
        if ($schema===null) {
            $this->database->createSchema($this->schema); (new Migrator($this->database->pdo,$this->schema))->migrate();
            Schema::install($this->database->pdo,$this->schema);
            $this->database->pdo->exec('SET search_path TO "'.$this->schema.'",pg_catalog');
            $this->database->pdo->exec('CREATE TABLE host_admissions(tenant text,key text,hash text,PRIMARY KEY(tenant,key));
                CREATE TABLE host_payloads(tenant text,id text,body text,hash text,PRIMARY KEY(tenant,id));
                CREATE TABLE host_provider(tenant text,id text,request_hash text,tools int,PRIMARY KEY(tenant,id));
                CREATE TABLE host_actions(tenant text,id text,hash text,PRIMARY KEY(tenant,id));
                CREATE TABLE host_accounting(tenant text,reservation text,kind text,payload text,calls int DEFAULT 1,PRIMARY KEY(tenant,reservation,kind,payload));
                CREATE TABLE host_meter(tenant text,id text,hash text,document jsonb,calls int DEFAULT 1,PRIMARY KEY(tenant,id));
                CREATE TABLE host_lookups(tenant text,key text,kind text);
                CREATE TABLE host_business(tenant text,id text,PRIMARY KEY(tenant,id))');
        }
        $this->rig=new TestRig($this->database->pdo,$this->schema,$tenant);
        $this->usage=new Repository($this->rig->fixture->environment->db,new TenantId($tenant),$this->rig->fixture->hasher,
            $policy ?? new BacklogPolicy(20,3600,3,1,1));
        $this->owner=new Owner($this);
        $this->budget=new Reservations($this->usage,$this->owner,$this->owner,$this->owner,$this->rig->fixture->hasher,$this->owner);
        $mapping=new ProviderMapping('model-usage-explicit-total-v1',['input'=>'inputTokens','output'=>'outputTokens','cache_read'=>'cacheReadTokens','cache_write'=>'cacheWriteTokens'],true,'separate','subset_of_output');
        $this->recorder=new Recorder($this->usage,$this->rig->fixture->hasher,$mapping);
        $this->rig->journal=new InvocationRepository($this->rig->fixture->environment,accounting:$this->recorder);
        $this->rig->provider->tools=false;
    }
    public function ref(string $id): Ref { return new Ref($this->usage->tenant,$id); }
    public function runtime(): AdvanceRun
    { return new AdvanceRun($this->rig->journal,$this->rig->provider,$this->budget,$this->rig->guard,$this->rig->payloads,$this->rig->actions,$this->rig->fixture->hasher); }
    public function invoke(): \AgenToolsPlatform\Core\Runtime\InvocationCheckpoint
    { return $this->runtime()->advance($this->rig->context,$this->rig->lease(),$this->rig->plan); }
    public function reservation(): Ref { return $this->ref('reserve:'.$this->rig->admission()->operationKey->value); }
    public function usageId(): Ref
    {
        $id=$this->rig->sql('SELECT id FROM accounting_usage ORDER BY usage_version DESC LIMIT 1')->fetchColumn();
        if (!is_string($id)) { throw new \RuntimeException('Missing durable usage'); } return $this->ref($id);
    }
    public function drop(): void
    { if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); } $this->database->dropSchema($this->schema); }
}
