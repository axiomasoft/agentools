<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Context\History\{Checks,MessageRef};

/** External-owner lifecycle value; no plaintext memory or unchecked domain assignment. */
final readonly class Candidate
{
    public PayloadHash $dedupKey;
    /** @var list<MessageRef> */ public array $sources;
    /** @param array<array-key,MessageRef> $sources */
    public function __construct(public Ref $ref,public CandidateType $type,public Ref $subject,public Ref $owner,
        public Ref $valueRef,public PayloadHash $valueHash,array $sources,public string $extractorVersion,public ?float $confidence,
        public Classification $sensitivity,public UtcInstant $validFrom,public UtcInstant $expiresAt,public string $retention,
        public int $version,public CandidateStatus $status,public ?Acceptance $acceptance,CanonicalHasher $hasher)
    {
        foreach ([$subject,$owner,$valueRef] as $link) { $ref->tenant->assertSame($link->tenant); }
        if (!array_is_list($sources) || $sources===[] || count($sources)>64 || $extractorVersion==='' || $retention===''
            || ($confidence!==null && (!is_finite($confidence) || $confidence<0 || $confidence>1)) || !Checks::future($expiresAt,$validFrom)) { throw new \InvalidArgumentException('Bounded evidenced memory metadata required'); }
        Checks::positive($version);foreach ($sources as $source) { $ref->tenant->assertSame($source->ref->tenant); }
        if ($acceptance!==null) { $owner->assertSame($acceptance->owner); }
        if (in_array($status,[CandidateStatus::HostAccepted,CandidateStatus::Active,CandidateStatus::Revalidated],true)
            && ($acceptance===null || ($type->requiresManualAcceptance() && !$acceptance->manual))) { throw new \InvalidArgumentException('Explicit owner/manual acceptance required'); }
        $this->sources=$sources;
        $this->dedupKey=$hasher->hash($ref->tenant,'memory-dedup',[$subject->value,$type->value,$valueHash->value]);
    }
    public function next(CandidateStatus $status,CanonicalHasher $hasher,?Acceptance $acceptance=null): self
    {
        return new \AgenToolsPlatform\Core\Context\Memory\Candidate($this->ref,$this->type,$this->subject,$this->owner,$this->valueRef,$this->valueHash,
            $this->sources,$this->extractorVersion,$this->confidence,$this->sensitivity,$this->validFrom,$this->expiresAt,$this->retention,
            $this->version+1,$status,$acceptance??$this->acceptance,$hasher);
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['candidate_ref'=>$this->ref->toArray(),'type'=>$this->type->value,'subject_ref'=>$this->subject->toArray(),'owner_ref'=>$this->owner->toArray(),
        'value_ref'=>$this->valueRef->toArray(),'value_hash'=>$this->valueHash->value,'sources'=>array_map(static fn(MessageRef $s): array=>$s->toArray(),$this->sources),
        'extractor_version'=>$this->extractorVersion,'confidence'=>$this->confidence,'sensitivity'=>$this->sensitivity->value,'valid_from'=>$this->validFrom->value,
        'expires_at'=>$this->expiresAt->value,'retention'=>$this->retention,'version'=>$this->version,'status'=>$this->status->value,
        'acceptance'=>$this->acceptance?->toArray(),'dedup_key'=>$this->dedupKey->value,'trust'=>'untrusted']; }
}
