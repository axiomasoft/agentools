<?php
require_once __DIR__ . '/manual/P01OpenEnum.php';
require_once __DIR__ . '/manual/P06IfThenElse.php';
require_once __DIR__ . '/manual/P08NestedDepth.php';
use Bakeoff\Manual\{P01OpenEnum, P06IfThenElse, P08NestedDepth};

$d1 = json_decode(file_get_contents(__DIR__.'/../probes/p01-open-enum/valid.json'), true);
$o1 = P01OpenEnum::fromArray($d1);
echo "p01 OK: "; var_export($o1->toArray()); echo "\n";

$d6 = json_decode(file_get_contents(__DIR__.'/../probes/p06-if-then-else/valid.json'), true);
$o6 = P06IfThenElse::fromArray($d6);
echo "p06 OK: "; var_export($o6->toArray()); echo "\n";
try { P06IfThenElse::fromArray(['kind'=>'llm']); echo "p06 FAIL: should have thrown\n"; }
catch (\InvalidArgumentException $e) { echo "p06 negative-OK: " . $e->getMessage() . "\n"; }

$d8 = json_decode(file_get_contents(__DIR__.'/../probes/p08-nested-depth/valid.json'), true);
$o8 = P08NestedDepth::fromArray($d8);
echo "p08 OK: "; var_export($o8->toArray()); echo "\n";
