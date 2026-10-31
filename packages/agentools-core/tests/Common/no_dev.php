<?php

declare(strict_types=1);

// Separate process: deliberately do not load the development SDK/JCS adapter.
require dirname(__DIR__, 3) . '/agentools-testing/tests/no-dev/vendor/autoload.php';

$ok = class_exists(\AgenToolsPlatform\Core\Security\ExecutionContext::class)
    && class_exists(\AgenToolsPlatform\Core\Common\CanonicalHasher::class)
    && !class_exists(\AgenTools\Schema\Canonicalizer::class)
    && !class_exists(\AgenToolsPlatform\Testing\CaseResult::class)
    && !class_exists(\AgenToolsPlatform\Tests\TestHost::class);
if ($ok) {
    $checkpoint = new \AgenToolsPlatform\Core\Common\RunCheckpoint(
        new \AgenToolsPlatform\Core\Common\RunId(new \AgenToolsPlatform\Core\Common\TenantId('tenant-a'), 'run-1'),
        \AgenToolsPlatform\Core\Common\RunStatus::Accepted, 1, 1,
    );
    $ok = $checkpoint->transition(\AgenToolsPlatform\Core\Common\RunStatus::Preparing, 1, 1)->version === 2;
}
echo json_encode(['mode' => 'production-no-dev', 'tier' => 'unit', 'cases' => [
    ['id' => 'packaging.new-core-no-sdk', 'status' => $ok ? 'passed' : 'failed'],
]], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($ok ? 0 : 1);
