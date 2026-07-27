<?php
require __DIR__ . '/vendor/autoload.php';
foreach (glob(__DIR__ . '/generated/model-gen/p08-nested-depth/*.php') as $f) require_once $f;
$cls = 'Bakeoff\p08_nested_depth\Httpsagentoolsdevbakeoffp08nesteddepthschemajson';
$data = json_decode(file_get_contents(__DIR__.'/../probes/p08-nested-depth/valid.json'), true);
try {
    $m = new $cls($data);
    echo "OK\n";
    var_export($m->getRawModelDataInput());
} catch (\Throwable $e) {
    echo "THREW: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
// invalid patternProperties key at l6 (doesn't match ^attr_[a-z0-9]+$)
$bad = $data;
$bad['l1']['l2']['l3']['l4']['l5']['l6'] = ['not_matching_key' => 'value'];
try {
    $m2 = new $cls($bad);
    echo "BAD-KEY: OK (no error - not enforced?)\n";
} catch (\Throwable $e) {
    echo "BAD-KEY: THREW " . get_class($e) . ": " . $e->getMessage() . "\n";
}
