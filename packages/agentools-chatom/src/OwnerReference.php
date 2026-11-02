<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenTools\Chatom\Data\SourceReference;
use AgenTools\Chatom\TranscriptNodes;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;

/** Typed result of a configured owning producer, never a model/client JSON conversion. */
final readonly class OwnerReference
{
    private function __construct(public SourceReference $source, public ?int $revision) {}

    /** Validate a trusted producer's runtime return at the SDK boundary.
     * @return list<self> */
    public static function batch(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 8) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        $seen = [];
        foreach ($value as $reference) {
            if (! $reference instanceof self || isset($seen[$reference->source->key()])) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            $seen[$reference->source->key()] = true;
        }

        return $value;
    }

    public static function knowledge(SourceReference $source, int $revision): self
    {
        if ($source->owner !== 'knowledge' || $revision < 1) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }

        return new self($source, $revision);
    }

    public static function artifact(SourceReference $source): self
    {
        if ($source->owner !== 'artifacts') {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }

        return new self($source, null);
    }

    /** @return array<string,mixed> */
    public function node(string $instance): array
    {
        return $this->revision === null
            ? TranscriptNodes::reference('agentools.artifact-ref', $this->source, $instance)
            : TranscriptNodes::citation($this->source, $this->revision, $instance);
    }
}
