<?php

declare(strict_types=1);

namespace AgenTools\Tests\Scenario;

use AgenTools\Scenario\ClarificationSession;
use PHPUnit\Framework\TestCase;

final class ClarificationSessionTest extends TestCase
{
    /** CLR-6, CLR-10, CLR-11, CLR-14, CLR-15: MRTR preserves opaque state and addressable rounds. */
    public function testItRecordsARequestAndAReplyWithADifferentCallId(): void
    {
        $session = new ClarificationSession();
        $session->request('rpc-1', 'opaque-state', ['counterparty_id' => ['mode' => 'form']]);
        $reply = $session->answer('rpc-2', ['counterparty_id' => 'cp-3391']);

        self::assertSame('opaque-state', $reply['request_state']);
        self::assertCount(2, $session->rounds());
    }

    /** CLR-11: answers may not invent request keys. */
    public function testItRejectsAnUnrequestedAnswer(): void
    {
        $session = new ClarificationSession();
        $session->request('rpc-1', 'opaque-state', ['counterparty_id' => ['mode' => 'form']]);
        $this->expectException(\InvalidArgumentException::class);
        $session->answer('rpc-2', ['unexpected' => 'value']);
    }
}
