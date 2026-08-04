<?php

declare(strict_types=1);

namespace AgenTools\Schema;

/** Retains every received member so an unknown extension round-trips unchanged. */
final readonly class RawDocument
{
    /** @param array<string, mixed> $raw */
    public function __construct(private array $raw, private ?string $originalJson = null)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('A raw document must be a JSON object.', previous: $exception);
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('A raw document must be a JSON object.');
        }
        $object = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('A raw document must be a JSON object.');
            }
            $object[$key] = $value;
        }

        return new self($object, $json);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** Returns the received document unchanged when it originated as JSON text. */
    public function toJson(): string
    {
        return $this->originalJson ?? json_encode(
            $this->raw,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
