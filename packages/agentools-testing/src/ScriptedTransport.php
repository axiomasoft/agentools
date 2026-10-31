<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\{InvocationId, OperationReceipt, PayloadHash};

/** Typed transport replies, no tool invocation/planning. Bridge to a model port in test composition. */
final class ScriptedTransport
{
    /** @param list<OperationReceipt> $script */
    public function __construct(private array $script)
    {
        foreach ($script as $reply) {
            if (!$reply->operationId instanceof InvocationId) { throw new \InvalidArgumentException('Expected Invocation replies'); }
        }
    }
    public function respond(InvocationId $id, PayloadHash $request): OperationReceipt
    {
        $reply = $this->script[0] ?? throw new \UnderflowException('Transport script exhausted');
        $reply->operationId->assertSame($id);
        $reply->requestHash->assertSame($request);
        array_shift($this->script);
        return $reply;
    }
    public function assertConsumed(): void
    {
        if ($this->script !== []) { throw new \LogicException('Unused transport reply'); }
    }
}
