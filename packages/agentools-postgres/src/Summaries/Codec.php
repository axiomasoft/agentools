<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Summaries;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Context\History\{MessageRef,SourceManifest,SourceStatus,SummaryFields,SummaryVersion,WorkingProposal,WorkingState};

/** Only operational/derived metadata is encoded, never exact transcript or full prompts. */
final readonly class Codec
{
    public function __construct(private CanonicalHasher $hasher) {}
    public function state(string $json): WorkingState
    {
        $m=self::object(json_decode($json,true,64,JSON_THROW_ON_ERROR));
        $p=new WorkingProposal(self::ref($m['branch']),self::optionalRef($m['goal_ref']),self::optionalRef($m['current_step_ref']),self::optionalRef($m['open_question_ref']),
            self::optionalRef($m['latest_result_ref']),self::optionalRef($m['active_ui_object_ref']),self::refs($m['promise_refs']),self::refs($m['action_refs']));
        return new WorkingState($p,self::number($m['version']),self::ref($m['acceptance_ref']),new UtcInstant(self::text($m['expires_at'])),self::text($m['retention']),self::number($m['schema_version']));
    }
    public function summary(string $json): SummaryVersion
    {
        $m=self::object(json_decode($json,true,64,JSON_THROW_ON_ERROR));$s=self::object($m['source_manifest']);
        $refs=[];foreach (self::list($s['messages']) as $value) {
            $r=self::object($value);$refs[]=new MessageRef(self::ref($r['ref']),self::ref($r['branch']),self::number($r['turn']),self::text($r['version']),new PayloadHash(self::text($r['hash'])));
        }
        $sources=new SourceManifest(self::ref($s['branch']),self::number($s['through_turn']),SourceStatus::from(self::text($s['status'])),self::boolean($s['complete']),$refs,$this->hasher);
        $sources->hash->assertSame(new PayloadHash(self::text($m['source_manifest_hash'])));$f=self::object($m['fields']);
        $fields=new SummaryFields(self::ref($f['view_ref']),self::refs($f['covered']),self::refs($f['understood']),self::refs($f['uncertain']),self::refs($f['open_questions']),self::refs($f['promises']),self::refs($f['artifact_refs']),self::refs($f['action_refs']));
        if (($m['trust']??null)!=='untrusted') { throw new \InvalidArgumentException('Summary trust cannot be promoted'); }
        return new SummaryVersion($sources,self::number($m['version']),$fields,new PayloadHash(self::text($m['view_hash'])),self::text($m['extractor_version']),
            Classification::from(self::text($m['classification'])),new UtcInstant(self::text($m['created_at'])),new UtcInstant(self::text($m['expires_at'])),$this->hasher);
    }
    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException('Metadata object required'); }$out=[];
        foreach ($value as $key=>$item) { if (!is_string($key)) { throw new \InvalidArgumentException('String keys required'); }$out[$key]=$item; }return $out;
    }
    /** @return list<mixed> */
    private static function list(mixed $value): array { if (!is_array($value)||!array_is_list($value)) { throw new \InvalidArgumentException('List required'); }return $value; }
    private static function text(mixed $value): string { if (!is_string($value)) { throw new \InvalidArgumentException('String required'); }return $value; }
    private static function number(mixed $value): int { if (!is_int($value)) { throw new \InvalidArgumentException('Integer required'); }return $value; }
    private static function boolean(mixed $value): bool { if (!is_bool($value)) { throw new \InvalidArgumentException('Boolean required'); }return $value; }
    private static function ref(mixed $value): Ref
    {
        $r=self::object($value);if (($r['kind']??null)!=='ref') { throw new \InvalidArgumentException('Opaque ref required'); }
        return new Ref(new TenantId(self::text($r['tenant_id'])),self::text($r['id']));
    }
    private static function optionalRef(mixed $value): ?Ref { return $value===null?null:self::ref($value); }
    /** @return list<Ref> */
    private static function refs(mixed $value): array { return array_map(self::ref(...),self::list($value)); }
}
