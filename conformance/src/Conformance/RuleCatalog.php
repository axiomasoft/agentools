<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

/** Нормативное слово и причина Not testable живут только в rules.json. */
final class RuleCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $rules;

    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromFile(string $path): self
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException("невалидный rules.json {$path}: {$error->getMessage()}");
        }
        if (!is_array($decoded['rules'] ?? null)) {
            throw new RuntimeException("rules.json {$path} не содержит rules");
        }
        $rules = [];
        foreach ($decoded['rules'] as $rule) {
            if (!is_array($rule) || !is_string($rule['rule_id'] ?? null) || !is_string($rule['normative_word'] ?? null)) {
                throw new RuntimeException("rules.json {$path} содержит неполное правило");
            }
            $rules[$rule['rule_id']] = $rule;
        }
        return new self($rules);
    }

    /** @param list<string> $ids */
    public function statusForFailure(array $ids): Status
    {
        $hasWarning = false;
        foreach ($ids as $id) {
            $word = $this->rule($id)['normative_word'];
            if (in_array($word, ['MUST', 'MUST NOT'], true)) {
                return Status::Fail;
            }
            if (in_array($word, ['SHOULD', 'SHOULD NOT'], true)) {
                $hasWarning = true;
                continue;
            }
            throw new RuntimeException("правило {$id} имеет неподдерживаемое нормативное слово {$word}");
        }
        return $hasWarning ? Status::Warn : throw new RuntimeException('вектор не содержит правил для severity');
    }

    /** @param list<string> $ids */
    public function notTestableReason(array $ids): ?string
    {
        foreach ($ids as $id) {
            $reason = $this->rule($id)['not_testable'] ?? null;
            if ($reason !== null) {
                if (!is_string($reason) || $reason === '') {
                    throw new RuntimeException("правило {$id} содержит невалидную причину Not testable");
                }
                return $reason;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function rule(string $id): array
    {
        return $this->rules[$id] ?? throw new RuntimeException("rules.json не содержит правило {$id}");
    }
}
