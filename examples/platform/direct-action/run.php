<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Examples\DirectAction\{Composition, Host};

// Host provisions/migrates the schema. This CLI never creates/drops a database or schema.
if (count($argv) !== 2) { fwrite(STDERR, "Usage: php run.php <provisioned-schema>\n"); exit(2); }
$pdo = new PDO((string) getenv('AGENTOOLS_TEST_PG_DSN'), (string) getenv('AGENTOOLS_TEST_PG_USER'),
    (string) getenv('AGENTOOLS_TEST_PG_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$composition = new Composition($pdo, $argv[1], new Host($pdo, new TenantId('direct-demo')));
$receipt = $composition->application->submit($composition->submission())->view;
$status = $composition->application->status($receipt->receipt->actionId, $composition->context());
echo json_encode(['receipt' => $receipt->receipt->toArray(), 'status' => $status->receipt->wireStatus,
    'outcome' => $status->outcome()?->value, 'safe_next_step' => $status->safeNextStep,
    'tier' => 'isolated-postgres', 'limitations' => 'Synthetic host; intake/status only; no dispatch or provider qualification.'], JSON_THROW_ON_ERROR) . "\n";
