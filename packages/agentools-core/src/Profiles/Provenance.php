<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

/** References must be verified by the host; their presence is not an evaluation PASS. */
final readonly class Provenance
{
    public function __construct(public string $createdByRef, public string $validationRef, public string $evaluationRef)
    {
        foreach ([$createdByRef, $validationRef, $evaluationRef] as $ref) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}$/D', $ref) !== 1) {
                throw new \InvalidArgumentException('Explicit bounded provenance references required');
            }
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['created_by_ref' => $this->createdByRef, 'validation_ref' => $this->validationRef, 'evaluation_ref' => $this->evaluationRef];
    }
}
