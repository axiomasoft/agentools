<?php

declare(strict_types=1);

namespace AgenTools\Error;

enum ToolErrorCode: string
{
    case IdempotencyKeyMissing = 'agentools.validation.idempotency_key_missing';
    case IdempotencyInFlight = 'agentools.conflict.idempotency_in_flight';
    case IdempotencyKeyReuse = 'agentools.validation.idempotency_key_reuse';
    case VersionConflict = 'agentools.conflict.version_conflict';
    case DescriptorDrift = 'agentools.precondition.descriptor_drift';
    case ConfirmationArgsMismatch = 'agentools.precondition.confirmation_args_mismatch';
    case ConfirmationExpired = 'agentools.precondition.confirmation_expired';
    case ConfirmationTokenSpent = 'agentools.precondition.confirmation_token_spent';
    case ConfirmationPrincipalMismatch = 'agentools.permission.confirmation_principal_mismatch';
    case ForeignAccessToken = 'agentools.permission.foreign_access_token';
    case ExtensionUnsupported = 'agentools.precondition.extension_unsupported';
    case ClarificationRoundsExhausted = 'agentools.budget.clarification_rounds_exhausted';
    case UnknownResultType = 'agentools.validation.unknown_result_type';
    case LimitExceeded = 'agentools.budget.limit_exceeded';
    case TaskInputRequiredWhileApplying = 'agentools.precondition.task_input_required_while_applying';
    case BaggageLimitExceeded = 'agentools.budget.baggage_limit_exceeded';
    case CanonicalizationFailed = 'agentools.validation.canonicalization_failed';
}
