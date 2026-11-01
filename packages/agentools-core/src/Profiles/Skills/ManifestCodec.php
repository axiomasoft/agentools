<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, ManifestCodec as BundleCodec, Provenance};
use AgenToolsPlatform\Core\Profiles\Composition\SafetyFloor;

/** Strict metadata schema: unknown/inline payload fields and changed hashes are rejected. */
final readonly class ManifestCodec
{
    public function __construct(private CanonicalJson $json) {}
    public function encode(SkillManifest $manifest): string { return $this->json->canonicalize($manifest->toArray()); }
    public function decode(string $wire): SkillManifest
    {
        $canonical=$this->json->canonicalizeJson($wire); $d=self::object(json_decode($canonical,true,64,JSON_THROW_ON_ERROR));
        if (($d['format'] ?? null) !== 'skill-manifest/v1' || !is_int($d['token_budget'] ?? null) || !is_int($d['safety_class'] ?? null)) { throw new \InvalidArgumentException('Typed skill metadata envelope required'); }
        $resource=BundleCodec::resource(self::object($d['resource'] ?? null));
        $compat=self::object($d['compatibility'] ?? null);
        $tools=[]; foreach (self::list($d['allowed_tools'] ?? null) as $tool) { $tools[]=self::definition(self::object($tool)); }
        $fragments=[];
        foreach (self::list($d['prompt_fragments'] ?? null) as $value) {
            $f=self::object($value); $identity=self::definition($f);
            $owner=BundleCodec::resource(['kind'=>'skill','id'=>$identity->id,'version'=>$identity->version,'hash'=>$identity->hash->value,'owner'=>$f['owner'] ?? null])->owner;
            $fragments[]=new \AgenToolsPlatform\Core\Profiles\Skills\FragmentRef($owner,$identity->id,$identity->version,$identity->hash,self::provenance(self::object($f['provenance'] ?? null)));
        }
        $manifest=new \AgenToolsPlatform\Core\Profiles\Skills\SkillManifest($resource->owner,$resource->version->id,$resource->version->version,
            self::text($d,'purpose'),self::list($d['triggers'] ?? null),self::list($d['required_context'] ?? null),$tools,$fragments,
            self::list($d['rubric_refs'] ?? null),$d['token_budget'],SafetyFloor::from($d['safety_class']),
            new \AgenToolsPlatform\Core\Profiles\Skills\Compatibility(BundleCodec::resource(self::object($compat['definition'] ?? null)),
                BundleCodec::resource(self::object($compat['profile'] ?? null)),self::definition(self::object($compat['compiler'] ?? null))),
            self::list($d['evaluation_refs'] ?? null),self::provenance(self::object($d['provenance'] ?? null)),$this->json,$resource->version->hash);
        if ($this->encode($manifest) !== $canonical) { throw new \InvalidArgumentException('Unexpected, duplicated or noncanonical skill metadata fields'); }
        return $manifest;
    }
    /**
     * @param array<string, mixed> $d
     */
    private static function definition(array $d): DefinitionRef
    {
        return new \AgenToolsPlatform\Core\Profiles\DefinitionRef(self::text($d,'id'),self::text($d,'version'),new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($d,'hash')));
    }
    /**
     * @param array<string, mixed> $d
     */
    private static function provenance(array $d): Provenance
    {
        return new \AgenToolsPlatform\Core\Profiles\Provenance(self::text($d,'created_by_ref'),self::text($d,'validation_ref'),self::text($d,'evaluation_ref'));
    }
    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException('Metadata object required'); }
        foreach ($value as $key=>$_) { if (!is_string($key)) { throw new \InvalidArgumentException('Metadata object names required'); } }
        return $value;
    }
    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \InvalidArgumentException('Explicit metadata list required'); } return $value;
    }
    /**
     * @param array<string, mixed> $value
     */
    private static function text(array $value,string $key): string
    {
        if (!is_string($value[$key] ?? null)) { throw new \InvalidArgumentException('Metadata string required'); } return $value[$key];
    }
}
