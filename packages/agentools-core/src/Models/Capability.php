<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum Capability: string
{
    case Text = 'text';
    case References = 'references';
    case Vision = 'vision';
    case Audio = 'audio';
    case Tools = 'tools';
    case StructuredOutput = 'structured_output';
    case Streaming = 'streaming';
    case Cache = 'cache';
    case Continuation = 'continuation';
    case ProviderOptions = 'provider_options';
}
