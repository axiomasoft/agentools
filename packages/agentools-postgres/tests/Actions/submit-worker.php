<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/examples/platform/direct-action/bootstrap.php';

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Examples\DirectAction\{Composition, Host};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

$database = new IsolatedDatabase();
$composition = new Composition($database->pdo, $argv[1], new Host($database->pdo, new TenantId('tenant-a')));
$mode = $argv[2];
$database->pdo->prepare("SELECT set_config('application_name',:name,false)")->execute(['name' => 'submit_' . $argv[1] . '_' . $mode]);
echo "ready\n"; fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') { exit(2); }
$receipt = $composition->application->submit($composition->submission())->view;
echo json_encode($receipt->receipt->toArray(), JSON_THROW_ON_ERROR) . "\n"; fflush(STDOUT);
if ($mode === 'after') { fgets(STDIN); }
