<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;

/** Immutable reference-only revision, with exact source coverage and view hash. */
final readonly class SummaryVersion
{
    public Ref $ref;
    public PayloadHash $hash;
    public function __construct(public SourceManifest $sources,public int $version,public SummaryFields $fields,public PayloadHash $viewHash,
        public string $extractorVersion,public Classification $classification,public UtcInstant $createdAt,public UtcInstant $expiresAt,CanonicalHasher $hasher)
    {
        Checks::positive($version);$sources->branch->tenant->assertSame($fields->viewRef->tenant);
        if ($sources->status!==SourceStatus::Available || !$sources->complete || $sources->messages===[] || $extractorVersion==='' || !Checks::future($expiresAt,$createdAt)) { throw new \InvalidArgumentException('Exact complete sources and bounded lifetime required'); }
        $this->hash=$hasher->hash($sources->branch->tenant,'history-summary',$this->toArray());
        $this->ref=new \AgenToolsPlatform\Core\Common\Ref($sources->branch->tenant,'summary:'.substr($this->hash->value,8));
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['source_manifest'=>$this->sources->toArray(),'source_manifest_hash'=>$this->sources->hash->value,'version'=>$this->version,
        'fields'=>$this->fields->toArray(),'view_hash'=>$this->viewHash->value,'extractor_version'=>$this->extractorVersion,'classification'=>$this->classification->value,
        'created_at'=>$this->createdAt->value,'expires_at'=>$this->expiresAt->value,'trust'=>'untrusted']; }
}
