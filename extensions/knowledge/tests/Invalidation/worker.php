<?php

declare(strict_types=1);

require __DIR__.'/pg-bootstrap.php';

use AgenToolsPlatform\Knowledge\Dependencies\{DeletionRequest,DeletionResult,DeletionTransport,DeletionWorker,PostgresGraph};
use AgenToolsPlatform\Knowledge\Tests\Invalidation\{Fixture,Policy};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

final class CrashConnection extends PDO
{
    public bool $armed=false;
    public function commit(): bool
    {
        if ($this->armed) { fwrite(STDOUT,"PRECOMMIT\n");fflush(STDOUT);fgets(STDIN); }
        return parent::commit();
    }
}
$mode=$argv[1];$schema=$argv[2];$event=$argv[3];
$db=new IsolatedDatabase();
$pdo=$mode==='crash-before'?new CrashConnection((string)getenv('AGENTOOLS_TEST_PG_DSN'),
    (string)getenv('AGENTOOLS_TEST_PG_USER'),(string)getenv('AGENTOOLS_TEST_PG_PASSWORD')):$db->pdo;
$policy=new Policy();$policy->pdo=$pdo;$graph=new PostgresGraph($pdo,$schema,$policy);$scope=Fixture::scope();$root=Fixture::node();
if ($pdo instanceof CrashConnection) { $pdo->armed=true; }
fwrite(STDOUT,"READY\n");fflush(STDOUT);fgets(STDIN);
try {
    if ($mode==='delete-crash') {
        $transport=new class($pdo,$schema) implements DeletionTransport {
            public function __construct(private PDO $pdo,private string $schema) {}
            public function delete(DeletionRequest $request): DeletionResult
            {
                if ($this->pdo->inTransaction()) { throw new LogicException('Provider IO inside graph transaction'); }
                $stmt=$this->pdo->prepare('INSERT INTO "'.$this->schema.'".invalidation_provider_effects VALUES (?) ON CONFLICT DO NOTHING');
                $stmt->execute([$request->id]);
                fwrite(STDOUT,"PROVIDER_DELETED\n");fflush(STDOUT);fgets(STDIN);
                return new DeletionResult('succeeded','provider:receipt');
            }
        };
        (new DeletionWorker($graph,$transport))->attempt($scope,$graph->requests($scope)[0],$event);
    }
    elseif ($mode==='register') { $graph->register($scope,Fixture::node('cache','race'),[$root]); }
    else { $graph->invalidate($scope,$root,'source_revoked',$event); }
    fwrite(STDOUT,json_encode(['status'=>'committed','backend'=>(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
    if ($mode==='crash-after') { fgets(STDIN); }
} catch (DomainException) {
    fwrite(STDOUT,json_encode(['status'=>'denied','backend'=>(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
}
