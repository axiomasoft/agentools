<?php

declare(strict_types=1);

if (!isset($argv[1]) || !is_file($argv[1])) { throw new RuntimeException('Required production install missing'); }
$loader = require $argv[1];
$failures = [];
foreach ([\AgenToolsPlatform\ModelAdapter\Anthropic\Transport::class, \AgenToolsPlatform\ModelAdapter\Http\CurlTransport::class, \AgenToolsPlatform\ModelAdapter\Http\CurlRuntime::class, \AgenToolsPlatform\Core\Models\ModelRequest::class] as $class) {
    if (!class_exists($class)) { $failures[] = 'Production class missing'; }
}
foreach (['AgenToolsPlatform\\Testing\\Models\\CancellationSwitch','AgenToolsPlatform\\ModelAdapterTests\\Fixture','AgenTools\\Reference\\Adapters\\ScriptedLlmPort','Illuminate\\Container\\Container','Laravel\\Ai\\Ai'] as $class) {
    if (class_exists($class)) { $failures[] = 'Development/framework class leaked'; }
}
new \AgenToolsPlatform\ModelAdapter\Anthropic\SchemaRules();
$report = ['mode' => 'production-no-dev', 'tier' => 'unit', 'cases' => [['id' => 'adapter.production-no-dev', 'status' => $failures === [] ? 'passed' : 'failed']], 'failures' => count($failures), 'skips' => 0, 'count' => 1];
echo json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL;
if ($failures !== []) { fwrite(STDERR, implode(PHP_EOL, $failures)); }
exit($failures === [] ? 0 : 1);
