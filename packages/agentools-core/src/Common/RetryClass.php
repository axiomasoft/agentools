<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

enum RetryClass: string
{
    case Never = 'never';
    case SafeSameKey = 'safe_same_key';
    case StatusLookup = 'status_lookup';
    case NewInvocation = 'new_invocation';
    case AfterPolicyRefresh = 'after_policy_refresh';
    case Manual = 'manual';
}
