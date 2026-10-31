<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$ok = class_exists(\AgenToolsPlatform\Core\Common\IntegrityConflict::class)
    && class_exists(\AgenToolsPlatform\Core\Runtime\RunState::class)
    && !class_exists(\AgenToolsPlatform\Testing\ScriptedTransport::class)
    && !class_exists(\AgenToolsPlatform\Testing\ScriptedDisclosure::class)
    && !class_exists(\AgenToolsPlatform\Testing\CaseResult::class)
    && !is_dir(__DIR__ . '/vendor/agentools/platform-testing');
echo json_encode(['mode' => 'production-no-dev', 'tier' => 'unit', 'cases' => [
    ['id' => 'packaging.no-dev', 'status' => $ok ? 'passed' : 'failed'],
]], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($ok ? 0 : 1);
