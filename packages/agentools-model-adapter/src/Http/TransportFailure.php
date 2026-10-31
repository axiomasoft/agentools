<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

use AgenToolsPlatform\Core\Common\ErrorCode;

/** Facts about the IO boundary only. Never contains raw curl/provider diagnostics. */
final class TransportFailure extends \RuntimeException
{
    public function __construct(public readonly ErrorCode $reason, public readonly bool $mayHaveSent)
    {
        parent::__construct($reason->value);
    }
}
