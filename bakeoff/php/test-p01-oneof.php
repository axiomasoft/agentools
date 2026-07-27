<?php
require __DIR__ . '/vendor/autoload.php';
foreach (glob(__DIR__ . '/generated/model-gen/p01-open-enum/*.php') as $f) require_once $f;
$cls = 'Bakeoff\p01_open_enum\Httpsagentoolsdevbakeoffp01openenumschemajson';
function t($cls,$d,$l){ try { new $cls($d); echo "$l: OK\n"; } catch (\Throwable $e) { echo "$l: THREW ".$e->getMessage()."\n"; } }
t($cls, ['kind'=>'llm'], 'known-const-llm');
t($cls, ['kind'=>'crm.custom_step'], 'fallback-pattern-match');
t($cls, ['kind'=>'NOT VALID AT ALL'], 'invalid-should-reject');
t($cls, ['kind'=>'123bad.pattern'], 'pattern-violates-leading-digit');
