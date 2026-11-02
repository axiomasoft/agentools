<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

enum Reason: string
{
    case Tenant = 'tenant_mismatch';
    case Legal = 'legal_denied_or_unknown';
    case Data = 'data_class_denied';
    case Region = 'region_denied';
    case Host = 'host_permission_denied_or_unknown';
    case Capability = 'capability_unverified_unknown_or_expired';
    case Admin = 'admin_state_denied';
    case Health = 'health_unavailable_or_unknown';
    case Quota = 'quota_denied_or_unknown';
    case Qualification = 'qualification_tuple_missing_rejected_or_expired';
    case Emergency = 'emergency_block';
    case Binding = 'pinned_catalog_changed';
    case Outage = 'previous_deployment_unavailable';
    case Rank = 'lower_rank';
}
