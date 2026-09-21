<?php

declare(strict_types=1);

namespace AgenTools\Tests\Adversarial;

use PHPUnit\Framework\TestCase;

final class PostgresScheduleTest extends TestCase
{
    public function testScheduleFileHasNoSleep(): void
    {
        $path = dirname(__DIR__, 3) . '/fixtures/hardening/adversarial/storage-schedules.v1.json';
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        self::assertDoesNotMatchRegularExpression('/sleep/i', $raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(
            ['SCH-EVT-CONCURRENT', 'SCH-IDC-TAKEOVER', 'SCH-TKN-CONCURRENT'],
            $decoded['catalog_ids'],
        );
    }

    public function testStorageChaosOffline(): void
    {
        $root = dirname(__DIR__, 3);
        $command = ['python3', $root . '/tools/storage_chaos.py', 'test'];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(0, $code, $stdout . $stderr);
    }
}
