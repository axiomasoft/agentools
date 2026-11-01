<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Actions\Effects\Request;
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};

/** Safe default. A FakeOwner without a qualified consumer boundary never executes a write. */
final class RejectEffects implements EffectBoundary
{
    public function reset(): void {}
    public function assertAccepted(Request $request): void { throw new ContractViolation(ErrorCode::ConfirmationRequired); }
}
