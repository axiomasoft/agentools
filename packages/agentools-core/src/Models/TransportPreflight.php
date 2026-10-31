<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\UtcInstant;

/** Capability evidence is necessary; adapters also validate exact options before sending. */
final class TransportPreflight
{
    public static function check(ModelRequest $request, Deployment $deployment, UtcInstant $now, bool $stream = false): void
    {
        $request->invocationId->tenant->assertSame($deployment->ref->tenant);
        if ($request->cancellation?->isCancellationRequested()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::Cancelled); }
        if (Time::compare($now, $request->deadline) >= 0) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::DeadlineExceeded); }
        $request->continuation?->assertUsable($deployment, $now);
        foreach ($request->requiredCapabilities($stream) as $capability) {
            if (!$deployment->supports($capability, $now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
        }
        foreach ($request->options as $option) {
            if ($option instanceof ProviderOption && $option->provider !== $deployment->provider) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
            }
        }
    }
}
