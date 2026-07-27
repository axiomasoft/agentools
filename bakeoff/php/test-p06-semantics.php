<?php
require __DIR__ . '/vendor/autoload.php';
foreach (glob(__DIR__ . '/generated/model-gen/p06-if-then-else/*.php') as $f) require_once $f;
$cls = 'Bakeoff\p06_if_then_else\Httpsagentoolsdevbakeoffp06ifthenelseschemajson';

function tryConstruct($cls, $data, $label) {
    try {
        new $cls($data);
        echo "$label: OK (no error)\n";
    } catch (\Throwable $e) {
        echo "$label: THREW " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
}

// if kind=llm then prompt_ref required — omitted here, expect error
tryConstruct($cls, ['kind' => 'llm'], 'llm-missing-prompt_ref');
// if kind=llm and prompt_ref present — expect OK
tryConstruct($cls, ['kind' => 'llm', 'prompt_ref' => 'p1'], 'llm-with-prompt_ref');
// dependentSchemas: side_effect=write without approval — expect error
tryConstruct($cls, ['kind' => 'tool', 'tool' => 't1', 'side_effect' => 'write'], 'write-missing-approval');
// side_effect=write with approval — expect OK
tryConstruct($cls, ['kind' => 'tool', 'tool' => 't1', 'side_effect' => 'write', 'approval' => 'confirm'], 'write-with-approval');
