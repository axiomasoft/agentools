<?php

declare(strict_types=1);

namespace AgenTools\Error;

enum ToolErrorCode: string
{
    case IdempotencyKeyMissing = 'agentools.validation.idempotency_key_missing';
    case IdempotencyInFlight = 'agentools.conflict.idempotency_in_flight';
    case IdempotencyKeyReuse = 'agentools.validation.idempotency_key_reuse';
    case IdempotencyLostLease = 'agentools.conflict.idempotency_lost_lease';
    case IdempotencyAmbiguousEffect = 'agentools.precondition.idempotency_ambiguous_effect';
    case IdempotencyCapabilityUnknown = 'agentools.precondition.idempotency_capability_unknown';
    case VersionConflict = 'agentools.conflict.version_conflict';
    case EventIdCollision = 'agentools.conflict.event_id_collision';
    case EventBatchPartialOverlap = 'agentools.conflict.event_batch_partial_overlap';
    case EventBatchEmpty = 'agentools.validation.event_batch_empty';
    case EventBatchLimit = 'agentools.validation.event_batch_limit';
    case EventIdDuplicateInBatch = 'agentools.validation.event_id_duplicate_in_batch';
    case EventIdSeqDerived = 'agentools.validation.event_id_seq_derived';
    case EventStreamIdentity = 'agentools.validation.event_stream_identity';
    case AuditForbiddenField = 'agentools.validation.audit_forbidden_field';
    case MetricLabelForbidden = 'agentools.validation.metric_label_forbidden';
    case EventPageLimit = 'agentools.budget.event_page_limit';
    case DescriptorDrift = 'agentools.precondition.descriptor_drift';
    case ConfirmationArgsMismatch = 'agentools.precondition.confirmation_args_mismatch';
    case ConfirmationExpired = 'agentools.precondition.confirmation_expired';
    case ConfirmationTokenSpent = 'agentools.precondition.confirmation_token_spent';
    case ConfirmationPrincipalMismatch = 'agentools.permission.confirmation_principal_mismatch';
    case ConfirmationTokenInvalid = 'agentools.validation.confirmation_token_invalid';
    case ConfirmationRecordMissing = 'agentools.precondition.confirmation_record_missing';
    case ConfirmationContextMismatch = 'agentools.permission.confirmation_context_mismatch';
    case ConfirmationPolicyDenied = 'agentools.permission.confirmation_policy_denied';
    case ConfirmationPolicyChanged = 'agentools.precondition.confirmation_policy_changed';
    case ConfirmationApprovalMissing = 'agentools.permission.confirmation_approval_missing';
    case ConfirmationApprovalStale = 'agentools.precondition.confirmation_approval_stale';
    case ConfirmationApprovalMismatch = 'agentools.precondition.confirmation_approval_mismatch';
    case ConfirmationQuorumInsufficient = 'agentools.permission.confirmation_quorum_insufficient';
    case ConfirmationSeparationViolation = 'agentools.permission.confirmation_separation_violation';
    case ConfirmationAuthorizationDenied = 'agentools.permission.confirmation_authorization_denied';
    case ConfirmationTokenKeyDenied = 'agentools.permission.confirmation_token_key_denied';
    case ConfirmationTokenBindingMismatch = 'agentools.permission.confirmation_token_binding_mismatch';
    case ConfirmationTokenNotYetValid = 'agentools.precondition.confirmation_token_not_yet_valid';
    case ConfirmationTokenCollision = 'agentools.conflict.confirmation_token_collision';
    case ForeignAccessToken = 'agentools.permission.foreign_access_token';
    case ExtensionUnsupported = 'agentools.precondition.extension_unsupported';
    case ClarificationRoundsExhausted = 'agentools.budget.clarification_rounds_exhausted';
    case UnknownResultType = 'agentools.validation.unknown_result_type';
    case LimitExceeded = 'agentools.budget.limit_exceeded';
    case TaskInputRequiredWhileApplying = 'agentools.precondition.task_input_required_while_applying';
    case BaggageLimitExceeded = 'agentools.budget.baggage_limit_exceeded';
    case CanonicalizationFailed = 'agentools.validation.canonicalization_failed';
}
