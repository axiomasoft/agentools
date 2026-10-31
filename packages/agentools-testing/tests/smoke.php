<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Testing\CaseResult;

$tests = [
    'testing.result' => static function (): void {
        $case = new CaseResult('one', 'passed');
        $coreError = new IntegrityConflict('core-autoload');
        if ($case->id !== 'one' || $case->status !== 'passed' || $coreError->getMessage() !== 'core-autoload') {
            throw new RuntimeException('Test reporting/core autoload mismatch');
        }
    },
    'testing.invalid-result' => static function (): void {
        foreach ([['', 'passed'], ['one', 'unknown']] as [$id, $status]) {
            try {
                new CaseResult($id, $status);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('Invalid case result was accepted');
        }
    },
];
$cases = [];
foreach ($tests as $id => $test) {
    try {
        $test();
        $cases[] = ['id' => $id, 'status' => 'passed'];
    } catch (Throwable $error) {
        $cases[] = ['id' => $id, 'status' => 'failed'];
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
    }
}
echo json_encode(['mode' => 'unit', 'tier' => 'unit', 'cases' => $cases], JSON_THROW_ON_ERROR) . PHP_EOL;
exit(in_array('failed', array_column($cases, 'status'), true) ? 1 : 0);
