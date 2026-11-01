<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{OwnerScope, Provenance};

/** Immutable content envelope from a trusted storage adapter. Text never sets trust or permissions. */
final readonly class Fragment
{
    public FragmentRef $ref;
    public function __construct(OwnerScope $owner, string $id, string $version, public string $text,
        public ContentKind $kind, public Classification $classification, Provenance $provenance,
        CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        if (trim($text) === '' || strlen($text) > 65536 || preg_match('//u', $text) !== 1) { throw new \InvalidArgumentException('Bounded UTF-8 fragment required'); }
        $hash = new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256', $json->canonicalize([
            'format'=>'skill-fragment/v1','owner'=>$owner->toArray(),'id'=>$id,'version'=>$version,
            'kind'=>$kind->value,'classification'=>$classification->value,'provenance'=>$provenance->toArray(),'text'=>$text])));
        $expectedHash?->assertSame($hash);
        $this->ref = new \AgenToolsPlatform\Core\Profiles\Skills\FragmentRef($owner,$id,$version,$hash,$provenance);
    }
    /**
     * Always data, including text authored by platform code or a third party.
     * @return array<string, mixed>
     */
    public function data(): array { return ['kind'=>'skill','trust'=>'untrusted','classification'=>$this->classification->value,'source'=>$this->ref->toArray(),'text'=>$this->text]; }
    /** Reconstruct to verify cache-returned body/classification/provenance against the stored digest. */
    public function verified(CanonicalJson $json): self
    {
        return new \AgenToolsPlatform\Core\Profiles\Skills\Fragment($this->ref->owner,$this->ref->version->id,$this->ref->version->version,
            $this->text,$this->kind,$this->classification,$this->ref->provenance,$json,$this->ref->version->hash);
    }
}
