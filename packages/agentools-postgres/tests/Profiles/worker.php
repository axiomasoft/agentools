<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Profiles\Fixture;

$db = new IsolatedDatabase();
$schema = $argv[1] ?? ''; $mode = $argv[2] ?? '';
$f = new Fixture($db->pdo, $schema);
$statement = $db->pdo->query('SELECT pg_backend_pid()');
if ($statement === false) { throw new RuntimeException('Backend unavailable'); }
echo json_encode(['ready' => true, 'pid' => (int) $statement->fetchColumn()], JSON_THROW_ON_ERROR) . "\n"; fflush(STDOUT);
if (fgets(STDIN) !== "go\n") { exit(2); }
$c = $f->context();
try {
    $result = match (str_replace('-pause', '', $mode)) {
        'publish' => $f->bundles->publish($c, $f->core->manifest()->bundle, 2),
        'pin' => $f->sessions->pin($c, $f->instance()),
        'migrate' => $f->sessions->migrate($c, $f->ref('migration-recovery'), 1, $f->core->manifest(8)->bundle, 'recover upgrade'),
        default => throw new RuntimeException('Unknown worker mode'),
    };
    $bundle = $result instanceof \AgenToolsPlatform\Postgres\Profiles\StoredBundle ? $result->manifest->bundle : $result->bundle;
    echo json_encode(['status' => 'committed', 'bundle' => $bundle->toArray(), 'revision' => $result->revision], JSON_THROW_ON_ERROR) . "\n";
} catch (ContractViolation $error) {
    if ($error->errorCode !== ErrorCode::OptimisticConflict || $mode !== 'publish') { throw $error; }
    echo json_encode(['status' => 'retry'], JSON_THROW_ON_ERROR) . "\n";
}
fflush(STDOUT);
if (str_ends_with($mode, '-pause')) { fgets(STDIN); }
