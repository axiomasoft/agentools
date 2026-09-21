<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionException;

final class ConfirmationFailure extends ToolExecutionException
{
    public static function argsMismatch(): self { return self::from(ToolErrorCode::ConfirmationArgsMismatch, 'Arguments do not match the confirmed proposal.'); }
    public static function expired(): self { return self::from(ToolErrorCode::ConfirmationExpired, 'The confirmation has expired.'); }
    public static function spent(): self { return self::from(ToolErrorCode::ConfirmationTokenSpent, 'The confirmation token was already presented.'); }
    public static function principalMismatch(): self { return self::from(ToolErrorCode::ConfirmationPrincipalMismatch, 'The confirmation principal does not match.'); }
    public static function tokenInvalid(): self { return self::from(ToolErrorCode::ConfirmationTokenInvalid, 'The confirmation token is invalid.'); }
    public static function recordMissing(): self { return self::from(ToolErrorCode::ConfirmationRecordMissing, 'The confirmation record is unavailable.'); }
    public static function contextMismatch(): self { return self::from(ToolErrorCode::ConfirmationContextMismatch, 'The confirmation context does not match.'); }
    public static function policyDenied(): self { return self::from(ToolErrorCode::ConfirmationPolicyDenied, 'The current confirmation policy denies this action.'); }
    public static function policyChanged(): self { return self::from(ToolErrorCode::ConfirmationPolicyChanged, 'The confirmation policy changed.'); }
    public static function approvalMissing(): self { return self::from(ToolErrorCode::ConfirmationApprovalMissing, 'Required approval is unavailable.'); }
    public static function approvalStale(): self { return self::from(ToolErrorCode::ConfirmationApprovalStale, 'The approval is no longer current.'); }
    public static function approvalMismatch(): self { return self::from(ToolErrorCode::ConfirmationApprovalMismatch, 'The approval does not match the confirmation.'); }
    public static function quorumInsufficient(): self { return self::from(ToolErrorCode::ConfirmationQuorumInsufficient, 'The approval quorum is insufficient.'); }
    public static function separationViolation(): self { return self::from(ToolErrorCode::ConfirmationSeparationViolation, 'The required approver separation is not satisfied.'); }
    public static function authorizationDenied(): self { return self::from(ToolErrorCode::ConfirmationAuthorizationDenied, 'Current authorization denied the action.'); }
    public static function keyDenied(): self { return self::from(ToolErrorCode::ConfirmationTokenKeyDenied, 'The confirmation token key is not usable.'); }
    public static function tokenBindingMismatch(): self { return self::from(ToolErrorCode::ConfirmationTokenBindingMismatch, 'The confirmation token binding does not match.'); }
    public static function notYetValid(): self { return self::from(ToolErrorCode::ConfirmationTokenNotYetValid, 'The confirmation token is not yet valid.'); }
    public static function collision(): self { return self::from(ToolErrorCode::ConfirmationTokenCollision, 'The confirmation token identity collides.'); }

    private static function from(ToolErrorCode $code, string $title): self
    {
        $parts = explode('.', $code->value);
        return new self(new ToolError($code->value, $parts[1], false, $title));
    }
}
