<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

enum Kind: string
{
    case Compiled = 'compiled_artifact';
    case Fragment = 'context_fragment';
    case Retrieval = 'retrieval_result';
    case ImplicitPrefix = 'implicit_prefix';
    case ProviderResource = 'provider_resource';
    case Conversation = 'provider_conversation';

    public function external(): bool
    { return in_array($this,[self::ImplicitPrefix,self::ProviderResource,self::Conversation],true); }
}
