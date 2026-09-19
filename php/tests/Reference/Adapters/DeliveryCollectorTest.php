<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Adapters;

use AgenTools\Reference\Adapters\DeliveryCollector;
use PHPUnit\Framework\TestCase;

final class DeliveryCollectorTest extends TestCase
{
    /** INT-002 §2.3; protocol objects are delivered without field renaming. */
    public function testItCollectsProtocolObjectsAsIs(): void
    {
        $collector = new DeliveryCollector('tenant-a');
        $clarification = [
            'type' => 'clarification_request',
            'request_state' => 'open',
            'requests' => [['field' => 'note_id', 'prompt' => 'Which note?']],
        ];
        $preview = [
            'type' => 'preview',
            'preview' => ['summary' => 'Draft note', 'changes' => [['entity' => 'note', 'entity_id' => 'n-1']]],
        ];
        $confirmation = [
            'type' => 'deferred_confirmation',
            'confirmation_token' => 'tok-1',
            'awaiting_confirmation' => true,
        ];

        $collector->deliver($clarification);
        $collector->deliver($preview);
        $collector->deliver($confirmation);

        self::assertSame([$clarification, $preview, $confirmation], $collector->collected());
    }

    /** Negative 4/4: tenant A does not receive tenant B deliveries. */
    public function testItIsolatesDeliveriesByTenant(): void
    {
        $tenantA = new DeliveryCollector('tenant-a');
        $tenantB = new DeliveryCollector('tenant-b');

        $tenantB->deliver(['type' => 'preview', 'preview' => ['summary' => 'B only', 'changes' => []]]);

        self::assertSame([], $tenantA->collected());
        self::assertCount(1, $tenantB->collected());
    }
}
