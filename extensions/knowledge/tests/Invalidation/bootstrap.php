<?php

declare(strict_types=1);

$runnerLoader=class_exists(\Composer\Autoload\ClassLoader::class,false)
    ? array_values(\Composer\Autoload\ClassLoader::getRegisteredLoaders())[0] : null;
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__,4).'/packages/agentools-core/tests/Context/History/bootstrap.php';
// Keep the running PHPUnit version ahead of unrelated SDK development dependencies.
if ($runnerLoader !== null) { $runnerLoader->unregister(); $runnerLoader->register(true); }
require_once __DIR__.'/Fixture.php';
