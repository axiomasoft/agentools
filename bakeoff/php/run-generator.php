<?php
// Прогон wol-soft/php-json-schema-model-generator по одной пробе bakeoff (P2.2).
// Использование: php run-generator.php <probe-id> <src-schema-dir> <dst-model-dir>
require __DIR__ . '/vendor/autoload.php';

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;

[, $probe, $srcDir, $dstDir] = $argv;

$generator = new ModelGenerator(
    (new GeneratorConfiguration())
        ->setNamespacePrefix('Bakeoff\\' . str_replace('-', '_', $probe))
        ->setImmutable(false)
);

$generator
    ->generateModelDirectory($dstDir)
    ->generateModels(new RecursiveDirectoryProvider($srcDir), $dstDir);

echo "OK\n";
