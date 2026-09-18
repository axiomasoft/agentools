<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;

/** Rejects tokens not issued for this implementation (SEC-1..SEC-6). */
final class CredentialGuard
{
    private const OUR_AUDIENCE = 'agentools';

    /**
     * @param array<string, mixed> $credential
     * @param list<string> $trustedIssuers
     */
    public function validateAccessToken(array $credential, array $trustedIssuers = ['issuer-trusted']): ?ToolExecutionResult
    {
        if (($credential['kind'] ?? null) !== 'access_token') {
            return null;
        }

        $reason = $this->rejectionReason($credential, $trustedIssuers);
        if ($reason === null) {
            return null;
        }

        return ToolExecutionResult::failure(new ToolError(
            ToolErrorCode::ForeignAccessToken->value,
            ErrorCategory::PERMISSION,
            false,
            'Access token was not issued for this implementation.',
            context: [
                'reason' => $reason,
                'security_rule' => 'SEC-3',
                'stimulus_pointer' => '/given/driver_state/credentials/0/audience',
            ],
        ));
    }

    /**
     * SEC-5; tokens embedded in tool arguments are never accepted.
     *
     * @param array<string, mixed> $arguments
     */
    public function rejectTokensInArguments(array $arguments): ?ToolExecutionResult
    {
        foreach ($arguments as $value) {
            if (is_array($value) && ($value['kind'] ?? null) === 'access_token') {
                return $this->foreignTokenFailure('token_in_argument');
            }
        }

        return null;
    }

    /** SEC-6; confirmation tokens cannot stand in for access tokens. */
    public function rejectConfirmationTokenAsAccess(string $tokenKind): ?ToolExecutionResult
    {
        if ($tokenKind !== 'confirmation_token') {
            return null;
        }

        return $this->foreignTokenFailure('confirmation_token_as_access');
    }

    /**
     * @param array<string, mixed> $credential
     * @param list<string> $trustedIssuers
     */
    private function rejectionReason(array $credential, array $trustedIssuers): ?string
    {
        if (($credential['audience'] ?? null) !== self::OUR_AUDIENCE) {
            return 'foreign_access_token';
        }
        if (($credential['source'] ?? null) === 'passthrough') {
            return 'passthrough_token';
        }
        $issuer = $credential['issuer'] ?? null;
        if (!is_string($issuer) || !in_array($issuer, $trustedIssuers, true)) {
            return 'untrusted_issuer';
        }
        if (($credential['integrity'] ?? null) !== 'verified') {
            return 'integrity_unverified';
        }

        return null;
    }

    private function foreignTokenFailure(string $reason): ToolExecutionResult
    {
        return ToolExecutionResult::failure(new ToolError(
            ToolErrorCode::ForeignAccessToken->value,
            ErrorCategory::PERMISSION,
            false,
            'Access token was not issued for this implementation.',
            context: ['reason' => $reason, 'security_rule' => 'SEC-3'],
        ));
    }
}
