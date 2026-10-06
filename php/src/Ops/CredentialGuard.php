<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;

/**
 * Rejects credentials that were not issued for this implementation (SEC-1, SEC-2, SEC-5, SEC-6).
 *
 * The guard is fail-closed: the resource audience and the trusted issuer set are declared by the
 * implementation (SEC-2 items 1 and 3) and have no built-in defaults; an empty issuer set rejects
 * every access token. A `null` verdict only means "not rejected" and never grants rights (SEC-1).
 * Every rejection is a non-retryable `permission` tool-execution error with its own code, so it
 * stays distinguishable from the confirmation refusals CNF-18..CNF-21 (SEC-3).
 *
 * The credential shape follows `DriverCredential` of the published vector schema: `kind`, `source`,
 * `issuer`, `audience` and `integrity`. Rejection context carries `reason`, `security_rule` and the
 * offending `credential_field`.
 */
final class CredentialGuard
{
    public const SOURCE_TRUSTED_CREDENTIAL = 'trusted_credential';

    private const MAX_ARGUMENT_DEPTH = 64;

    /** @var list<non-empty-string> */
    private readonly array $trustedIssuers;

    /**
     * @param string $resourceAudience identifier of our own resource (SEC-2 item 1)
     * @param list<string> $trustedIssuers declared trusted issuer set (SEC-2 item 3)
     */
    public function __construct(
        private readonly string $resourceAudience,
        array $trustedIssuers,
    ) {
        if ($resourceAudience === '') {
            throw new \InvalidArgumentException('The resource audience must not be empty.');
        }

        $issuers = [];
        foreach ($trustedIssuers as $issuer) {
            if ($issuer === '') {
                throw new \InvalidArgumentException('A trusted issuer must not be empty.');
            }
            $issuers[$issuer] = $issuer;
        }
        $this->trustedIssuers = array_values($issuers);
    }

    /**
     * SEC-1, SEC-2, SEC-5, SEC-6; validates a credential presented as an access token.
     *
     * @param array<string, mixed> $credential
     */
    public function validateAccessToken(array $credential): ?ToolExecutionResult
    {
        $kind = $credential['kind'] ?? null;
        if ($kind === 'confirmation_token') {
            return $this->rejectCredential('credential_role_confusion', 'SEC-6', 'kind');
        }
        if ($kind !== 'access_token') {
            return $this->rejectCredential('unsupported_credential_kind', 'SEC-1', 'kind');
        }
        if (($credential['source'] ?? null) !== self::SOURCE_TRUSTED_CREDENTIAL) {
            return $this->rejectCredential('data_sourced_access_token', 'SEC-5', 'source');
        }
        if (($credential['audience'] ?? null) !== $this->resourceAudience) {
            return $this->rejectCredential('foreign_access_token', 'SEC-1', 'audience');
        }
        $issuer = $credential['issuer'] ?? null;
        if (!is_string($issuer) || !in_array($issuer, $this->trustedIssuers, true)) {
            return $this->rejectCredential('untrusted_issuer', 'SEC-2', 'issuer');
        }
        if (($credential['integrity'] ?? null) !== 'verified') {
            return $this->rejectCredential('unverified_access_token', 'SEC-2', 'integrity');
        }

        return null;
    }

    /**
     * SEC-5; an access token found anywhere inside tool arguments is data, never a credential.
     *
     * Nested maps and lists are inspected. Structures deeper than the inspection limit are rejected
     * as well, because the absence of a smuggled token cannot be proven for them.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function rejectTokensInArguments(array $arguments): ?ToolExecutionResult
    {
        return $this->scanArguments($arguments, '', 1);
    }

    /** SEC-6; confirmation tokens cannot stand in for access tokens. */
    public function rejectConfirmationTokenAsAccess(string $tokenKind): ?ToolExecutionResult
    {
        if ($tokenKind !== 'confirmation_token') {
            return null;
        }

        return $this->failure(['reason' => 'credential_role_confusion', 'security_rule' => 'SEC-6']);
    }

    /** @param array<array-key, mixed> $value */
    private function scanArguments(array $value, string $pointer, int $depth): ?ToolExecutionResult
    {
        if ($depth > self::MAX_ARGUMENT_DEPTH) {
            return $this->failure([
                'reason' => 'argument_depth_exceeded',
                'security_rule' => 'SEC-5',
                'argument_pointer' => $pointer,
            ]);
        }
        if (($value['kind'] ?? null) === 'access_token') {
            return $this->failure([
                'reason' => 'data_sourced_access_token',
                'security_rule' => 'SEC-5',
                'argument_pointer' => $pointer,
            ]);
        }
        foreach ($value as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            $found = $this->scanArguments($child, $pointer . '/' . self::escapePointerToken((string) $key), $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function rejectCredential(string $reason, string $rule, string $field): ToolExecutionResult
    {
        return $this->failure(['reason' => $reason, 'security_rule' => $rule, 'credential_field' => $field]);
    }

    /** @param array<string, string> $context */
    private function failure(array $context): ToolExecutionResult
    {
        return ToolExecutionResult::failure(new ToolError(
            ToolErrorCode::ForeignAccessToken->value,
            ErrorCategory::PERMISSION,
            false,
            'Access token was not issued for this implementation.',
            context: $context,
        ));
    }

    private static function escapePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
