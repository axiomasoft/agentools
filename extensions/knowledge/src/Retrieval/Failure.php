<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

/** Payload-free failure codes; adapter exception messages are never exposed. */
final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $status, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
