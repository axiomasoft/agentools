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

use AgenTools\Schema\Canonicalizer;

$canonicalizer = new Canonicalizer();
$json = $canonicalizer->canonicalizeJson('{"probe":"p7.4"}');
if ($json !== '{"probe":"p7.4"}') {
    fwrite(STDERR, "canonicalizer probe failed\n");
    exit(1);
}

if (class_exists(\AgenTools\TestKit\FixedClock::class)) {
    fwrite(STDERR, "testkit FQN must not autoload in --no-dev consumer\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");
