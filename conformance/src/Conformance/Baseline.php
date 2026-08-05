<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

/** Базис допускает только уже наблюдённые отказы и считает их исчезновение ошибкой. */
final class Baseline
{
    /** @var array<string,array<string,mixed>> */
    private array $entries;

    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromFile(?string $path): self
    {
        if ($path === null) {
            return self::empty();
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException("невалидный baseline {$path}: {$error->getMessage()}");
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException("baseline {$path} должен быть JSON object");
        }
        foreach ($decoded as $key => $entry) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]*(?::[A-Za-z0-9_.-]+)?$/D', (string) $key) || !is_array($entry)) {
                throw new RuntimeException("baseline {$path} содержит невалидную запись {$key}");
            }
            foreach (['reason', 'owner', 'since', 'issue'] as $required) {
                if (!is_string($entry[$required] ?? null) || $entry[$required] === '') {
                    throw new RuntimeException("baseline {$path}: запись {$key} не содержит {$required}");
                }
            }
        }
        return new self($decoded);
    }

    /** @param list<string> $mismatches */
    public function matches(string $vectorId, array $mismatches): bool
    {
        if ($mismatches === []) {
            return false;
        }
        foreach (array_keys($mismatches) as $index) {
            if (!isset($this->entries["{$vectorId}:{$index}"]) && !isset($this->entries[$vectorId])) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $report */
    public function stale(array $report): array
    {
        $actual = [];
        foreach ($report as $entry) {
            foreach (array_keys($entry['mismatches'] ?? []) as $index) {
                $actual[$entry['id']] = true;
                $actual[$entry['id'] . ':' . $index] = true;
            }
        }
        return array_values(array_filter(array_keys($this->entries), static fn(string $key): bool => !isset($actual[$key])));
    }
}
