<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

/** These are different resources; an instance is a tenant assignment, not a definition. */
enum ResourceKind: string
{
    case Definition = 'assistant_definition';
    case Profile = 'profile';
    case ModelRevision = 'model_revision';
    case ProviderAccount = 'provider_account';
    case Deployment = 'deployment';
    case Skill = 'skill';
    case Methodology = 'methodology_pack';
    case Knowledge = 'knowledge_release';
    case ExecutionPolicy = 'execution_policy';
}
