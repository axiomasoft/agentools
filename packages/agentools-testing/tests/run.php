<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$tests = [];
foreach (['ports','guards','faults'] as $group) {
    foreach (\AgenToolsPlatform\Tests\Harness::loadCases(__DIR__ . '/' . $group . '.php') as $id => $test) {
        if (isset($tests[$id])) { throw new RuntimeException('Duplicate testkit case'); }
        $tests[$id] = $test;
    }
}
\AgenToolsPlatform\Tests\Harness::run($tests,'contract-scripted','contract-scripted');
