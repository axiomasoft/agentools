<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

final readonly class TextRead
{
    public function __construct(public SourceStatus $status,public ?string $text=null)
    {
        if (($status===SourceStatus::Available)!==($text!==null)) { throw new \InvalidArgumentException('Explicit view availability required'); }
    }
}
