<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

enum ErrorCode: string
{
    case InvalidInput = 'invalid_input';
    case Unauthenticated = 'unauthenticated';
    case AccessDenied = 'access_denied';
    case PolicyConflict = 'policy_conflict';
    case StaleContext = 'stale_context';
    case IntegrityConflict = 'integrity_conflict';
    case OptimisticConflict = 'optimistic_conflict';
    case StaleClaim = 'stale_claim';
    case ConfirmationRequired = 'confirmation_required';
    case ConfirmationInvalid = 'confirmation_invalid';
    case CapabilityUnavailable = 'capability_unavailable';
    case DeploymentUnavailable = 'deployment_unavailable';
    case AdmissionDenied = 'admission_denied';
    case ProviderTimeout = 'provider_timeout';
    case InvocationUnknown = 'invocation_unknown';
    case EffectUnknown = 'effect_unknown';
    case DeliveryUnknown = 'delivery_unknown';
    case ManualResolutionRequired = 'manual_resolution_required';
    case Cancelled = 'cancelled';
    case DeadlineExceeded = 'deadline_exceeded';

    public function requiresUnknown(): bool
    {
        return in_array($this, [self::InvocationUnknown, self::EffectUnknown,
            self::DeliveryUnknown, self::ManualResolutionRequired], true);
    }

    /** @return list<RetryClass> */
    public function allowedRetries(): array
    {
        return match ($this) {
            self::InvalidInput, self::Cancelled => [RetryClass::Never],
            self::IntegrityConflict => [RetryClass::Never, RetryClass::Manual],
            self::AccessDenied, self::ConfirmationInvalid => [RetryClass::Never, RetryClass::AfterPolicyRefresh],
            self::OptimisticConflict => [RetryClass::SafeSameKey, RetryClass::StatusLookup],
            self::StaleClaim => [RetryClass::StatusLookup],
            self::ProviderTimeout, self::InvocationUnknown, self::EffectUnknown,
            self::DeliveryUnknown, self::ManualResolutionRequired => [RetryClass::StatusLookup, RetryClass::Manual],
            self::DeadlineExceeded => [RetryClass::Never, RetryClass::StatusLookup, RetryClass::Manual],
            self::DeploymentUnavailable => [RetryClass::AfterPolicyRefresh, RetryClass::NewInvocation],
            self::Unauthenticated, self::PolicyConflict, self::StaleContext,
            self::ConfirmationRequired, self::CapabilityUnavailable, self::AdmissionDenied => [RetryClass::AfterPolicyRefresh],
        };
    }
}
