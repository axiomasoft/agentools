<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;

final class Harness
{
    public static function check(bool $condition, string $message = 'Contract assertion failed'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param callable(): mixed $stimulus
     * @param class-string<\Throwable> $class
     */
    public static function rejects(callable $stimulus, string $class, ?ErrorCode $code = null): void
    {
        try {
            $stimulus();
        } catch (\Throwable $error) {
            self::check($error instanceof $class, 'Wrong rejection: ' . $error::class . ': ' . $error->getMessage());
            if ($code !== null) {
                self::check($error instanceof ContractViolation && $error->errorCode === $code, 'Wrong public error semantics');
            }
            return;
        }
        throw new \RuntimeException('Expected rejection was not raised');
    }

    /** @return array<string, \Closure(): void> */
    public static function loadCases(string $path): array
    {
        $loaded = require $path;
        if (!is_array($loaded) || $loaded === []) {
            throw new \RuntimeException('Missing/empty test file');
        }
        $cases = [];
        foreach ($loaded as $id => $callback) {
            if (!is_string($id) || !$callback instanceof \Closure) {
                throw new \RuntimeException('A test needs a named closure');
            }
            $cases[$id] = static function () use ($callback): void {
                $callback();
            };
        }
        return $cases;
    }

    /** @param array<string, callable(): void> $tests */
    public static function run(array $tests, string $mode = 'contract-scripted', string $tier = 'contract-scripted'): never
    {
        $cases = [];
        $failures = 0;
        foreach ($tests as $id => $test) {
            try {
                $test();
                $cases[] = ['id' => $id, 'status' => 'passed'];
            } catch (\Throwable $error) {
                ++$failures;
                $cases[] = ['id' => $id, 'status' => 'failed'];
                fwrite(STDERR, $id . ': ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
            }
        }
        echo json_encode(['mode' => $mode, 'tier' => $tier, 'cases' => $cases,
            'count' => count($cases), 'failures' => $failures, 'skips' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($tests !== [] && $failures === 0 ? 0 : 1);
    }
}
