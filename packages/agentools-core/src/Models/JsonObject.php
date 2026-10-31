<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

/** JSON-only object: no callbacks, resources, secrets wrapper or provider client. */
final readonly class JsonObject
{
    /** @var array<string, mixed> */
    public array $value;

    /** @param array<array-key, mixed> $value */
    public function __construct(array $value)
    {
        $object = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) { throw new \InvalidArgumentException('Expected object keys'); }
            self::validate($entry);
            $object[$key] = $entry;
        }
        $this->value = $object;
    }

    public function sameValue(self $other): bool
    {
        return self::ordered($this->value) === self::ordered($other->value);
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $entry) { $value[$key] = self::ordered($entry); }
        return $value;
    }

    private static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 64) { throw new \InvalidArgumentException('JSON nesting limit'); }
        if (is_array($value)) {
            foreach ($value as $entry) { self::validate($entry, $depth + 1); }
        } elseif ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)
            && !(is_float($value) && is_finite($value))) {
            throw new \InvalidArgumentException('Expected JSON data only');
        }
    }
}
