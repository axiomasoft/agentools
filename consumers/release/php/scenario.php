<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found\n");
    exit(1);
}
require $autoload;

use AgenTools\Schema\Canonicalizer;

$canonicalizer = new Canonicalizer();
$json = $canonicalizer->canonicalizeJson('{"probe":"p10.3"}');
if ($json !== '{"probe":"p10.3"}') {
    fwrite(STDERR, "canonicalizer probe failed\n");
    exit(1);
}

if (class_exists(\AgenTools\TestKit\FixedClock::class)) {
    fwrite(STDERR, "testkit FQN must not autoload in --no-dev consumer\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");
