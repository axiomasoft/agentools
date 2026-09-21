<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Scenario\ReferenceConfirmationServiceFactory;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use AgenTools\Reference\Tools\ReferenceSliceToolFactory;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class ConfirmationServiceTest extends TestCase
{
    public function testManifestHappyPathNoApproval(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/confirmation-security-v1.json');
        self::assertIsString($raw);
        /** @var array{cases: list<array{scenario: string, result: string}>} $manifest */
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $case = null;
        foreach ($manifest['cases'] as $row) {
            if ($row['scenario'] === 'happy_path_no_approval') {
                $case = $row;
                break;
            }
        }
        self::assertNotNull($case);
        self::assertSame('allow', $case['result']);

        $port = new RegistryToolPort();
        (new ReferenceSliceToolFactory(new DayReportStore()))->registerOn($port, 'tenant-a');
        $port->bindSubject(
            ['initiator' => ['id' => 'user-a'], 'agent' => ['id' => 'agent-a'], 'authorizer' => ['id' => 'user-a']],
            ['resource' => 'day-report', 'tool' => 'slice_write_day_report', 'permissions' => ['slice.write']],
        );
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $canonicalizer = new Canonicalizer();
        $service = (new ReferenceConfirmationServiceFactory())->create($port, $clock, 'tenant-a', 'user-a', ['slice.write']);

        $args = ['report_id' => 'r-1', 'date' => '2026-09-19', 'body' => 'body'];
        $preview = ['summary' => 'Persist day report', 'changes' => []];
        $token = $service->prepare('act-1', 'slice_write_day_report', $args, $preview);
        $committed = $service->commit($token->value, $args);
        self::assertSame($token->value, $committed->value);
    }
}
