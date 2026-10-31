<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SpecFixtures.php';

$cases = \AgenToolsPlatform\Tests\SpecFixtures::cases(dirname(__DIR__, 4));
if (isset($argv[1])) {
    $selected = [];
    foreach (array_slice($argv, 1) as $id) {
        if (!isset($cases[$id])) {
            throw new RuntimeException('Missing selected fixture case');
        }
        $selected[$id] = $cases[$id];
    }
    $cases = $selected;
}
\AgenToolsPlatform\Tests\Harness::run($cases, 'schema-profile', 'design-schema');
