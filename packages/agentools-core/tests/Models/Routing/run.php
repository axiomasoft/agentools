<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
$groups = ['catalog','filters','boundary','fallback'];
if (isset($argv[1])) {
    if (!in_array($argv[1],$groups,true)) { throw new InvalidArgumentException('Unknown routing test group'); }
    $groups = [$argv[1]];
}
$tests = [];
foreach ($groups as $group) {
    foreach (\AgenToolsPlatform\Tests\Harness::loadCases(__DIR__.'/'.$group.'.php') as $id=>$test) {
        if (isset($tests[$id])) { throw new RuntimeException('Duplicate routing case'); }
        $tests[$id] = $test;
    }
}
\AgenToolsPlatform\Tests\Harness::run($tests);
