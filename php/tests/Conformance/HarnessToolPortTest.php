<?php

declare(strict_types=1);

namespace AgenTools\Tests\Conformance;

use AgenTools\Conformance\HarnessToolPort;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/conformance/HarnessToolPort.php';

final class HarnessToolPortTest extends TestCase
{
    public function testCallReportsTheToolBoundaryThroughTheProbe(): void
    {
        /** @var list<array<string,mixed>> $messages */
        $messages = [];
        $port = new HarnessToolPort(
            static function (array $message) use (&$messages): array {
                $messages[] = $message;
                return [];
            },
            'scenario-001',
        );

        $result = $port->call('pay', ['amount' => 5], 'at1:step-key-0001');

        self::assertFalse($result->isError());
        self::assertSame(['ok' => true], $result->value);
        self::assertSame([[
            'type' => 'probe_call',
            'vector_id' => 'scenario-001',
            'probe_id' => 'scenario-pay-at1:step-key-0001',
            'probe_kind' => 'tool_boundary',
            'operation' => ['tool' => 'pay', 'phase' => 'prepare', 'args' => ['amount' => 5]],
        ]], $messages);
    }
}
