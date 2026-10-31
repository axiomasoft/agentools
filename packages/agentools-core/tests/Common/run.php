<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$tests = [];
foreach (['identities', 'context', 'state', 'canonical', 'errors', 'receipts', 'requests', 'schema_controls'] as $group) {
    $cases = \AgenToolsPlatform\Tests\Harness::loadCases(__DIR__ . '/' . $group . '.php');
    foreach ($cases as $id => $case) {
        if (isset($tests[$id])) {
            throw new RuntimeException('Duplicate contract case ID');
        }
        $tests[$id] = $case;
    }
}
\AgenToolsPlatform\Tests\Harness::run($tests);
