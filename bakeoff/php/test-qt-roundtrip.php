<?php
require_once __DIR__ . '/generated/quicktype/p01-open-enum.php';
$data = json_decode(file_get_contents(__DIR__.'/../probes/p01-open-enum/unknown-key.json'));
try {
    $obj = Bakeoffp01OpenEnum::from($data);
    $out = $obj->to();
    echo "IN: "; var_dump($data);
    echo "OUT: "; var_dump($out);
    echo "UNKNOWN-FIELD-SURVIVES: " . (property_exists($out, 'x_unknown_probe_field') ? 'yes' : 'no') . "\n";
} catch (\Throwable $e) {
    echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
