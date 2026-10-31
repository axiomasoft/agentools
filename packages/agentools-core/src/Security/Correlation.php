<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

final readonly class Correlation
{
    public function __construct(public string $traceId, public string $requestId)
    {
        foreach ([$traceId, $requestId] as $id) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $id) !== 1) {
                throw new \InvalidArgumentException('Opaque trace and request IDs are required');
            }
        }
    }

    /** @return array{trace_id: string, request_id: string} */
    public function toArray(): array
    {
        return ['trace_id' => $this->traceId, 'request_id' => $this->requestId];
    }
}
