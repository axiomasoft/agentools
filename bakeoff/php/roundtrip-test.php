<?php
// Раунд-трип теста генератора PHP по unknown-key.json (P2.2).
// Использование: php roundtrip-test.php <probe> <generated-dir> <class-fqn> <unknown-key.json>
require __DIR__ . '/vendor/autoload.php';

[, $probe, $genDir, $classFqn, $inputFile] = $argv;

foreach (glob($genDir . '/*.php') as $file) {
    require_once $file;
}

$data = json_decode(file_get_contents($inputFile), true);
echo "INPUT: " . json_encode($data) . "\n";

try {
    $model = new $classFqn($data);
    echo "CONSTRUCTED OK\n";
    $raw = $model->getRawModelDataInput();
    echo "RAW-ROUNDTRIP: " . json_encode($raw) . "\n";
    $hasUnknown = array_key_exists('x_unknown_probe_field', $raw);
    echo "UNKNOWN-FIELD-SURVIVES-RAW: " . ($hasUnknown ? 'yes' : 'no') . "\n";
} catch (\Throwable $e) {
    echo "CONSTRUCT-THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
