<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** Known const branches plus a product fallback pattern — not a closed PHP enum. */
final readonly class OpenEnum
{
    private const FALLBACK_PATTERN = '/^[a-z][a-z0-9]*\.[a-z0-9_]+$/';

    /**
     * @param list<string> $known
     * @param array<string, ValueLifecycle> $lifecycle
     */
    public function __construct(
        public string $value,
        private array $known,
        private array $lifecycle = [],
    ) {
        if (!in_array($value, $known, true) && preg_match(self::FALLBACK_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf("Value '%s' matches neither a known const nor the product fallback pattern.", $value));
        }

        $stage = $this->lifecycle[$value] ?? null;
        if ($stage === ValueLifecycle::Removed) {
            throw new \InvalidArgumentException(sprintf("Value '%s' was removed after its deprecation window.", $value));
        }
    }

    public function isKnown(): bool
    {
        return in_array($this->value, $this->known, true);
    }

    public function lifecycle(): ValueLifecycle
    {
        return $this->lifecycle[$this->value] ?? ValueLifecycle::Active;
    }

    /**
     * @param list<string> $known
     * @param array<string, ValueLifecycle> $lifecycle
     */
    public static function producerValue(string $value, array $known, array $lifecycle = []): self
    {
        $stage = $lifecycle[$value] ?? ValueLifecycle::Active;
        if ($stage === ValueLifecycle::Deprecated) {
            throw new \InvalidArgumentException(sprintf("Value '%s' is deprecated and must not be emitted in new records.", $value));
        }

        return new self($value, $known, $lifecycle);
    }
}
