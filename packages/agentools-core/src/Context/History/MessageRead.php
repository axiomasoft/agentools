<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

final readonly class MessageRead
{
    public function __construct(public SourceStatus $status,public ?ExactMessage $message=null)
    {
        if (($status===SourceStatus::Available)!==($message!==null)) { throw new \InvalidArgumentException('Unavailable is not an empty successful message'); }
    }
}
