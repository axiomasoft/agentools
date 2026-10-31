<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$groups = ['request', 'response', 'stream', 'ownership'];
if (isset($argv[1])) {
    if (!in_array($argv[1], [...$groups, 'curl'], true)) { throw new InvalidArgumentException('Unknown focused file'); }
    $groups = [$argv[1]];
}
$tests = [];
foreach ($groups as $group) {
    foreach (\AgenToolsPlatform\Tests\Harness::loadCases(__DIR__ . '/' . $group . '.php') as $id => $case) {
        if (isset($tests[$id])) { throw new RuntimeException('Duplicate adapter case'); }
        $tests[$id] = $case;
    }
}
\AgenToolsPlatform\Tests\Harness::run($tests);
