<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use AgenToolsPlatform\Core\Common\IntegrityConflict;

$cases = [];
try {
    $error = new IntegrityConflict('identity/hash conflict');
    $parent = (new ReflectionClass($error))->getParentClass();
    if ($parent === false || $parent->getName() !== DomainException::class || $error->getMessage() !== 'identity/hash conflict') {
        throw new RuntimeException('Core exception contract mismatch');
    }
    $cases[] = ['id' => 'core.exception', 'status' => 'passed'];
} catch (Throwable $error) {
    $cases[] = ['id' => 'core.exception', 'status' => 'failed'];
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
}
echo json_encode(['mode' => 'unit', 'tier' => 'unit', 'cases' => $cases], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($cases[0]['status'] === 'passed' ? 0 : 1);
