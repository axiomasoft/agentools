<?php

declare(strict_types=1);

namespace AgenTools\Identity;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;

final class ExtensionNegotiator
{
    /** @param array<string, mixed> $counterpartyExtensions */
    public function negotiate(array $counterpartyExtensions, bool $extensionRequired): NegotiationResult
    {
        if (ExtensionAnnouncement::fromExtensions($counterpartyExtensions)->supportsCurrentRevision()) {
            return new NegotiationResult(NegotiationMode::AgenTools);
        }
        if (!$extensionRequired) {
            return new NegotiationResult(NegotiationMode::CoreProtocol);
        }

        return new NegotiationResult(NegotiationMode::Rejected, new ToolError(
            ToolErrorCode::ExtensionUnsupported->value,
            ErrorCategory::PRECONDITION,
            false,
            'The counterparty has not declared the required AgenTools extension.',
        ));
    }
}
