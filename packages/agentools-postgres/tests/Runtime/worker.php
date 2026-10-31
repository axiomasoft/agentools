<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;

$database = new IsolatedDatabase();
$schema = $argv[1];
$mode = $argv[2];
$name = $argv[3];
$statement = $database->pdo->prepare("SELECT set_config('application_name', :name, false)");
$statement->execute(['name' => $name]);
$fixture = new Fixture($database->pdo, $schema, new TenantId('tenant-a'));
$context = $fixture->context();
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}
$receipt = (new IntakeRepository($fixture->environment))->accept($fixture->input($context), $fixture->snapshot($context));
if ($mode === 'crash') {
    // Commit happened; terminate before any queue transport or client receipt.
    exit(73);
}
echo json_encode($receipt->toArray(), JSON_THROW_ON_ERROR) . "\n";
