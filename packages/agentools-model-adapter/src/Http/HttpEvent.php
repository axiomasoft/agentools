<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

final readonly class HttpEvent
{
    private function __construct(public ?int $status, public ?string $requestId, public ?string $bytes) {}
    public static function headers(int $status, ?string $requestId = null): self
    {
        if ($status < 200 || $status > 599) { throw new \InvalidArgumentException('Invalid final HTTP status'); }
        return new self($status, $requestId, null);
    }
    public static function body(string $bytes): self { return new self(null, null, $bytes); }
}
