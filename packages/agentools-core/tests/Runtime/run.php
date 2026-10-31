<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Common/bootstrap.php';
$tests = [];
foreach ([__DIR__ . '/fsm.php', dirname(__DIR__) . '/Actions/registry.php', dirname(__DIR__) . '/Actions/facade.php'] as $path) {
    foreach (\AgenToolsPlatform\Tests\Harness::loadCases($path) as $id => $test) {
        if (isset($tests[$id])) { throw new RuntimeException('Duplicate FSM/registry case'); }
        $tests[$id] = $test;
    }
}
\AgenToolsPlatform\Tests\Harness::run($tests, 'unit', 'unit');
