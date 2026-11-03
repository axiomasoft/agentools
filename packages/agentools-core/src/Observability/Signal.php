<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

enum Signal: string
{
    case QueueDelay='queue_delay_ms'; case RunLatency='run_latency_ms'; case CompilerLatency='compiler_latency_ms';
    case SectionTokens='section_tokens'; case CacheHit='cache_hit'; case CacheCost='cache_cost_minor';
    case ProviderLatency='provider_latency_ms'; case ToolLatency='tool_latency_ms'; case ActionLatency='action_latency_ms';
    case ReconcileLatency='reconcile_latency_ms'; case DeliveryLatency='delivery_latency_ms'; case OutboxAge='outbox_age_ms';
    case PrivacyDenial='privacy_denial'; case BudgetReject='budget_reject'; case VoiceInterruption='voice_interruption';
    case UsageUnits='usage_units';
}
