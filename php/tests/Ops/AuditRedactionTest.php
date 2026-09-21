<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Error\EventStoreFailure;
use AgenTools\Ops\AuditRedaction;
use PHPUnit\Framework\TestCase;

final class AuditRedactionTest extends TestCase
{
    public function testForbiddenTokenFieldIsDenied(): void
    {
        $this->expectException(EventStoreFailure::class);
        (new AuditRedaction())->assertPayload(['token' => 'secret']);
    }

    public function testAllowedAuditFieldsPass(): void
    {
        (new AuditRedaction())->assertPayload([
            'tool_name' => 'pay',
            'side_effect' => 'write',
            'verdict' => 'allow',
        ]);
        $document = (new AuditRedaction())->redactDocument([
            'kind' => 'audit.policy_evaluated',
            'payload' => [
                'tool_name' => 'pay',
                'side_effect' => 'write',
                'verdict' => 'allow',
            ],
        ]);
        $payload = $document['payload'];
        self::assertIsArray($payload);
        self::assertSame('pay', $payload['tool_name']);
    }
}
