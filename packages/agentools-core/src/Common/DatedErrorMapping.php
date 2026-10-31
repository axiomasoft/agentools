<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final class DatedErrorMapping
{
    public static function fromDated(string $datedCode): ?ErrorCode
    {
        return match ($datedCode) {
            'agentools.validation.confirmation_token_invalid', 'agentools.validation.idempotency_key_missing' => ErrorCode::InvalidInput,
            'agentools.permission.confirmation_authorization_denied', 'agentools.permission.confirmation_policy_denied' => ErrorCode::AccessDenied,
            'agentools.precondition.confirmation_policy_changed' => ErrorCode::PolicyConflict,
            'agentools.precondition.descriptor_drift' => ErrorCode::StaleContext,
            'agentools.validation.idempotency_key_reuse', 'agentools.conflict.event_id_collision' => ErrorCode::IntegrityConflict,
            'agentools.conflict.version_conflict' => ErrorCode::OptimisticConflict,
            'agentools.conflict.idempotency_lost_lease' => ErrorCode::StaleClaim,
            'agentools.precondition.confirmation_args_mismatch', 'agentools.precondition.confirmation_expired', 'agentools.precondition.confirmation_token_spent', 'agentools.permission.confirmation_context_mismatch' => ErrorCode::ConfirmationInvalid,
            'agentools.precondition.idempotency_capability_unknown' => ErrorCode::CapabilityUnavailable,
            'agentools.precondition.idempotency_ambiguous_effect' => ErrorCode::EffectUnknown,
            default => null,
        };
    }
}
