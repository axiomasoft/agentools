<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, UtcInstant};

/** Saved, owner-measured baseline and candidate observations, never an inline judge. */
final readonly class Measurements
{
    /** @param array<string,int|float|null> $values */
    public function __construct(public Ref $evidence, public Ref $baseline, public PayloadHash $baselineHash,
        public PayloadHash $tupleHash, public int $samples, public array $values, public int $hardViolations,
        public UtcInstant $measuredAt, public UtcInstant $expiresAt)
    {
        $evidence->tenant->assertSame($baseline->tenant);
        if ($samples < 0 || $hardViolations < 0) { throw new \InvalidArgumentException('Nonnegative measured counts required'); }
        foreach ($values as $name => $value) {
            if ($name === '') { throw new \InvalidArgumentException('Named measured value required'); }
            if ($value !== null) { Data::finite($value); }
        }
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['evidence'=>$this->evidence->value,'baseline'=>$this->baseline->value,'baseline_hash'=>$this->baselineHash->value,
        'tuple_hash'=>$this->tupleHash->value,'samples'=>$this->samples,'values'=>$this->values,'hard_violations'=>$this->hardViolations,
        'measured_at'=>$this->measuredAt->value,'expires_at'=>$this->expiresAt->value]; }
}
