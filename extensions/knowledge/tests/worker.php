<?php

declare(strict_types=1);

require __DIR__ . '/pg-bootstrap.php';

use AgenToolsPlatform\Knowledge\Adapters\Postgres\Repository;
use AgenToolsPlatform\Knowledge\Tests\Fixture;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

final class CrashConnection extends PDO
{
    public bool $armed = false;
    public function commit(): bool
    {
        if ($this->armed) {
            fwrite(STDOUT, "PRECOMMIT\n"); fflush(STDOUT);
            fgets(STDIN); // Parent SIGKILLs the real repository transaction at this boundary.
        }
        return parent::commit();
    }
}

$mode = $argv[1]; $schema = $argv[2]; $id = $argv[3]; $version = $argv[4];
$database = new IsolatedDatabase(); // Prove isolation before opening the crash-injecting connection.
$pdo = $mode === 'crash-before' ? new CrashConnection((string)getenv('AGENTOOLS_TEST_PG_DSN'),
    (string)getenv('AGENTOOLS_TEST_PG_USER'), (string)getenv('AGENTOOLS_TEST_PG_PASSWORD')) : $database->pdo;
$f = new Fixture(version: $version); $f->owner->pdo = $pdo;
$repository = new Repository($pdo, $schema, $f->owner);
if ($pdo instanceof CrashConnection) { $pdo->armed = true; }
fwrite(STDOUT, "READY\n"); fflush(STDOUT); fgets(STDIN);
try {
    $release = $f->pipeline($repository)->ingest($f->scope, 'corpus:school', $id, $f->material);
    fwrite(STDOUT, json_encode(['status'=>'published', 'hash'=>$release->hash(), 'pid'=>getmypid()], JSON_THROW_ON_ERROR) . "\n"); fflush(STDOUT);
    if ($mode === 'crash-after') { fgets(STDIN); }
} catch (DomainException $error) {
    fwrite(STDOUT, json_encode(['status'=>'conflict', 'pid'=>getmypid()], JSON_THROW_ON_ERROR) . "\n");
}
