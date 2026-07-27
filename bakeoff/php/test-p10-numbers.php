<?php
require __DIR__ . '/vendor/autoload.php';
foreach (glob(__DIR__ . '/generated/model-gen/p10-numbers/*.php') as $f) require_once $f;
$cls = 'Bakeoff\p10_numbers\Httpsagentoolsdevbakeoffp10numbersschemajson';
$data = json_decode(file_get_contents(__DIR__.'/../probes/p10-numbers/valid.json'), true);
var_dump($data);
$m = new $cls($data);
echo "amount_minor: "; var_dump($m->getAmountMinor());
echo "big_integer: "; var_dump($m->getBigInteger());
echo "scientific: "; var_dump($m->getScientific());
echo "raw: "; var_dump($m->getRawModelDataInput());
