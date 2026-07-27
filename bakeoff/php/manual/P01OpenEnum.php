<?php
declare(strict_types=1);

namespace Bakeoff\Manual;

final class P01OpenEnum
{
    private const KNOWN = ['llm', 'tool', 'elicitation'];
    private const FALLBACK_PATTERN = '/^[a-z][a-z0-9]*\.[a-z0-9_]+$/';

    public function __construct(public readonly string $kind)
    {
        if (!in_array($kind, self::KNOWN, true) && !preg_match(self::FALLBACK_PATTERN, $kind)) {
            throw new \InvalidArgumentException("kind '$kind' matches neither known const ни fallback pattern");
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data['kind']);
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind];
    }
}
