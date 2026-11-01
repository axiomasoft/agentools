<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Summaries;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Context\History\{SummaryVersion,WorkingState};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection,ContextRevision,RepositoryContext};
use AgenToolsPlatform\Postgres\Summaries\{Authority,Repository,Schema};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Context\History\Fixture as HistoryFixture;
use AgenToolsPlatform\Tests\TestHost;

final class Host implements Authority
{
    public bool $denied=false;
    public function __construct(private \PDO $pdo) {}
    public function authorize(ExecutionContext $context,Ref $scope,string $operation): void
    { if ($this->pdo->inTransaction()) { throw new \LogicException('Owner IO under repository transaction'); }if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); } }
    public function acceptsState(ExecutionContext $context,?WorkingState $before,WorkingState $next): void
    { if ($next->acceptance->value!=='state:accepted') { throw new ContractViolation(ErrorCode::AccessDenied); } }
    public function acceptsSummary(ExecutionContext $context,SummaryVersion $summary): void
    { if ($summary->extractorVersion!=='extractor:v1') { throw new ContractViolation(ErrorCode::AccessDenied); } }
}
final class Fixture
{
    public IsolatedDatabase $database;public string $schema;public HistoryFixture $h;public Host $authority;public TestHost $host;public Repository $repo;public Connection $db;
    public function __construct(?string $schema=null)
    {
        $this->database=new IsolatedDatabase();$this->schema=$schema??'p3_'.bin2hex(random_bytes(12));
        if ($schema===null) { $this->database->createSchema($this->schema); }Schema::install($this->database->pdo,$this->schema);
        $this->h=new HistoryFixture();$this->authority=new Host($this->database->pdo);$this->host=new TestHost($this->h->core->tenant);$this->db=new Connection($this->database->pdo,$this->schema);
        $revisions=new class implements ContextRevision { public function current(ExecutionContext $context,Ref $ref): int { return 1; } };
        $this->repo=new Repository(new RepositoryContext($this->db,$this->h->core->hasher,$this->host,$revisions),$this->authority);
    }
    public function summary(int $through=10,int $version=1,string $text='baseline'): SummaryVersion
    {
        $f=$this->h;$f->extractor->text=$text;$manifest=$f->source->manifest($f->core->context,$f->branch,$through);$messages=[];
        foreach ($manifest->messages as $ref) { $messages[]=$f->source->messages[$ref->ref->value]; }
        $draft=$f->extractor->extract($f->core->context,$f->branch,$messages,$f->state);
        return new SummaryVersion($manifest,$version,$draft->fields,$f->core->hasher->hash($f->core->tenant,'history-summary-view',$draft->modelView),$draft->extractorVersion,Classification::Private,$f->core->now,$draft->expiresAt,$f->core->hasher);
    }
    public function count(string $table): int
    { $row=$this->db->row('SELECT count(*) AS total FROM '.$this->db->table($table));return $row===null?throw new \RuntimeException('Count missing'):Connection::number($row,'total'); }
    public function close(): void { $this->database->dropSchema($this->schema); }
}
