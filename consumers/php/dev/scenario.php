<?php

declare(strict_types=1);

$autoload = null;
foreach ([getcwd(), __DIR__] as $root) {
    $candidate = $root . '/vendor/autoload.php';
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "vendor/autoload.php not found\n");
    exit(1);
}
require $autoload;

use AgenTools\TestKit\FixedClock;

$clock = new FixedClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
if ($clock->now()->format('Y') !== '2026') {
    fwrite(STDERR, "FixedClock probe failed\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");
