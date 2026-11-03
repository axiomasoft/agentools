<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Budget\BudgetBoundary;
use AgenToolsPlatform\Core\Common\{CanonicalHasher,TenantId};
use AgenToolsPlatform\Core\Usage\ProviderMapping;
use AgenToolsPlatform\ModelAdapter\Cache\{Manager,WarmAccounting};
use AgenToolsPlatform\Postgres\Cache\{Repository,Schema};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\JcsAdapter;

final class PgFixture
{
    public IsolatedDatabase $database;
    public string $schema;
    public Repository $repository;
    public \AgenToolsPlatform\Postgres\Usage\Repository $usage;
    public Fixture $f;
    public function __construct(?string $schema=null)
    {
        $this->database=new IsolatedDatabase(); $this->schema=$schema ?? 'p3_'.bin2hex(random_bytes(12));
        if ($schema===null) {
            $this->database->createSchema($this->schema); Schema::install($this->database->pdo,$this->schema);
            \AgenToolsPlatform\Postgres\Usage\Schema::install($this->database->pdo,$this->schema);
            $this->database->pdo->exec('CREATE TABLE "'.$this->schema.'".host_cache_receipts(id text PRIMARY KEY,kind text NOT NULL)');
        }
        $db=new Connection($this->database->pdo,$this->schema); $hasher=new CanonicalHasher(new JcsAdapter());
        $this->repository=new Repository($db,new TenantId('alpha'),$hasher,1); $this->f=new Fixture($this->repository);
        $this->usage=new \AgenToolsPlatform\Postgres\Usage\Repository($db,new TenantId('alpha'),$hasher,new \AgenToolsPlatform\Postgres\Usage\BacklogPolicy(20,3600,3));
        $mapping=new ProviderMapping('fixture-cache-total-v1',['input'=>'inputTokens','output'=>'outputTokens','cache_read'=>'cacheReadTokens','cache_write'=>'cacheWriteTokens','provider_amount'=>'amount'],true,'separate','subset_of_output');
        $this->f->accounting=new WarmAccounting(new BudgetBoundary($this->f->budget,$this->f->budget,$hasher),$this->usage,$hasher,$mapping);
        $this->f->manager=new Manager($this->repository,$this->f->host,$this->f->provider,$this->f->host,$this->f->host,$this->f->accounting,fn ()=>$this->f->now);
    }
    /** @param array<string,scalar|null> $parameters */
    public function sql(string $sql,array $parameters=[]): \PDOStatement
    {
        $this->database->pdo->exec('SET search_path TO "'.$this->schema.'",pg_catalog');
        $s=$this->database->pdo->prepare($sql); $s->execute($parameters); return $s;
    }
    public function drop(): void
    { if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); } $this->database->dropSchema($this->schema); }
}
