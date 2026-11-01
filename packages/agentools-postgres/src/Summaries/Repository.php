<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Summaries;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,RunId};
use AgenToolsPlatform\Core\Context\History\{DerivedKind,Derivations,MessageRef,SummaryStore,SummaryVersion,WorkingState};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext};

/** Reference-only immutable versions, optimistic heads and durable source invalidation. */
final readonly class Repository implements SummaryStore,Derivations
{
    private Codec $codec;
    public function __construct(private RepositoryContext $environment,private Authority $authority)
    { $this->codec=new Codec($environment->hasher); }
    public function state(ExecutionContext $context,Ref $branch): ?WorkingState
    {
        $this->authorize($context,$branch,'state.read');$db=$this->environment->db;
        $r=$db->row('SELECT v.payload,v.content_hash FROM '.$db->table('working_versions').' v JOIN '.$db->table('working_heads').' h USING(tenant_id,branch,version) WHERE h.tenant_id=:tenant AND h.branch=:branch',$this->key($branch));
        if ($r===null) { return null; }$state=$this->codec->state(Connection::text($r,'payload'));$branch->assertSame($state->value->branch);
        if ($this->hash($branch,'working-state',$state->toArray())!==Connection::text($r,'content_hash')) { throw new ContractViolation(ErrorCode::IntegrityConflict); }return $state;
    }
    public function saveState(ExecutionContext $context,WorkingState $state,int $expectedVersion): void
    {
        $branch=$state->value->branch;$this->authorize($context,$branch,'state.write');$before=$this->state($context,$branch);$this->authority->acceptsState($context,$before,$state);
        $hash=$this->hash($branch,'working-state',$state->toArray());$db=$this->environment->db;
        $db->transaction(function() use($db,$branch,$state,$expectedVersion,$hash): void {
            $this->lock($branch,'state');$key=$this->key($branch);
            $old=$db->row('SELECT version,content_hash FROM '.$db->table('working_versions').' WHERE tenant_id=:tenant AND branch=:branch AND version=:version',$key+['version'=>$state->version]);
            if ($old!==null) { if (Connection::text($old,'content_hash')!==$hash || $state->version!==$expectedVersion+1) { throw new ContractViolation(ErrorCode::IntegrityConflict); }return; }
            $head=$db->row('SELECT version FROM '.$db->table('working_heads').' WHERE tenant_id=:tenant AND branch=:branch FOR UPDATE',$key);
            if (($head===null?0:Connection::number($head,'version'))!==$expectedVersion || $state->version!==$expectedVersion+1) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $db->execute('INSERT INTO '.$db->table('working_versions').' VALUES(:tenant,:branch,:version,:hash,:payload)',$key+['version'=>$state->version,'hash'=>$hash,'payload'=>json_encode($state->toArray(),JSON_THROW_ON_ERROR)]);
            $db->execute('INSERT INTO '.$db->table('working_heads').' VALUES(:tenant,:branch,:version) ON CONFLICT(tenant_id,branch) DO UPDATE SET version=EXCLUDED.version',$key+['version'=>$state->version]);
        });
    }
    public function head(ExecutionContext $context,Ref $branch): ?SummaryVersion
    {
        $this->authorize($context,$branch,'summary.read');$db=$this->environment->db;
        $r=$db->row('SELECT v.payload,v.content_hash FROM '.$db->table('summary_versions').' v JOIN '.$db->table('summary_heads').' h USING(tenant_id,branch,version) WHERE h.tenant_id=:tenant AND h.branch=:branch',$this->key($branch));
        if ($r===null) { return null; }$summary=$this->codec->summary(Connection::text($r,'payload'));$branch->assertSame($summary->sources->branch);
        if ($summary->hash->value!==Connection::text($r,'content_hash')) { throw new ContractViolation(ErrorCode::IntegrityConflict); }return $summary;
    }
    public function append(ExecutionContext $context,SummaryVersion $summary,int $expectedVersion): void
    {
        $branch=$summary->sources->branch;$this->authorize($context,$branch,'summary.write');$this->authority->acceptsSummary($context,$summary);$db=$this->environment->db;
        $db->transaction(function() use($db,$branch,$summary,$expectedVersion): void {
            $this->lock($branch,'summary');$key=$this->key($branch);
            $old=$db->row('SELECT content_hash FROM '.$db->table('summary_versions').' WHERE tenant_id=:tenant AND branch=:branch AND version=:version',$key+['version'=>$summary->version]);
            if ($old!==null) { if (Connection::text($old,'content_hash')!==$summary->hash->value || $summary->version!==$expectedVersion+1) { throw new ContractViolation(ErrorCode::IntegrityConflict); }$this->registerInside($summary->ref,DerivedKind::Summary,$summary->sources->messages,[]);return; }
            $head=$db->row('SELECT h.version,v.through_turn FROM '.$db->table('summary_heads').' h JOIN '.$db->table('summary_versions').' v USING(tenant_id,branch,version) WHERE h.tenant_id=:tenant AND h.branch=:branch FOR UPDATE',$key);
            if (($head===null?0:Connection::number($head,'version'))!==$expectedVersion || $summary->version!==$expectedVersion+1
                || ($head!==null && $summary->sources->throughTurn<Connection::number($head,'through_turn'))) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $this->registerInside($summary->ref,DerivedKind::Summary,$summary->sources->messages,[]);
            $db->execute('INSERT INTO '.$db->table('summary_versions').' VALUES(:tenant,:branch,:version,:id,:hash,:through,:payload)',
                $key+['version'=>$summary->version,'id'=>$summary->ref->value,'hash'=>$summary->hash->value,'through'=>$summary->sources->throughTurn,'payload'=>json_encode($summary->toArray(),JSON_THROW_ON_ERROR)]);
            $db->execute('INSERT INTO '.$db->table('summary_heads').' VALUES(:tenant,:branch,:version) ON CONFLICT(tenant_id,branch) DO UPDATE SET version=EXCLUDED.version',$key+['version'=>$summary->version]);
        });
    }
    public function register(ExecutionContext $context,Ref $derived,DerivedKind $kind,array $sources,array $completedRuns=[]): void
    {
        $this->authorize($context,$derived,'derivation.register');
        $this->environment->db->transaction(function() use($derived,$kind,$sources,$completedRuns): void { $this->registerInside($derived,$kind,$sources,$completedRuns); });
    }
    public function usable(ExecutionContext $context,Ref $derived): bool
    {
        $this->authorize($context,$derived,'derivation.use');$r=$this->environment->db->row('SELECT invalid_reason FROM '.$this->environment->db->table('derived_refs').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$derived->tenant->value,'id'=>$derived->value]);
        return $r!==null && $r['invalid_reason']===null;
    }
    public function invalidate(ExecutionContext $context,MessageRef $source,string $reason): void
    {
        $this->authorize($context,$source->branch,'source.invalidate');
        if (!in_array($reason,['source_revoked','source_deleted','source_corrected','consent_revoked'],true)) { throw new \InvalidArgumentException('Known source event required'); }$db=$this->environment->db;
        $db->transaction(function() use($db,$source,$reason): void {
            $this->pin($source);$key=['tenant'=>$source->ref->tenant->value,'source'=>$source->ref->value,'version'=>$source->version,'reason'=>$reason];
            $db->execute('INSERT INTO '.$db->table('source_tombstones').' VALUES(:tenant,:source,:version,:reason) ON CONFLICT DO NOTHING',$key);
            $filter=$reason==='source_corrected'?' AND e.source_version=:version':'';$params=['tenant'=>$key['tenant'],'source'=>$key['source'],'reason'=>$reason];if ($filter!=='') { $params['version']=$source->version; }
            $db->execute('UPDATE '.$db->table('derived_refs').' d SET invalid_reason=:reason WHERE d.tenant_id=:tenant AND EXISTS(SELECT 1 FROM '.$db->table('source_edges').' e WHERE e.tenant_id=d.tenant_id AND e.derived_id=d.id AND e.source_id=:source'.$filter.')',$params);
            $db->execute('INSERT INTO '.$db->table('historical_source_markers').' SELECT DISTINCT e.tenant_id,r.run_id,e.source_id,:reason FROM '.$db->table('source_edges').' e JOIN '.$db->table('derived_runs').' r ON r.tenant_id=e.tenant_id AND r.derived_id=e.derived_id WHERE e.tenant_id=:tenant AND e.source_id=:source'.$filter.' ON CONFLICT DO NOTHING',$params);
        });
    }
    /** @return list<array{source_id:string,reason:string}> */
    public function markers(ExecutionContext $context,RunId $run): array
    {
        $ref=new Ref($run->tenant,$run->value);$this->authorize($context,$ref,'run.markers');
        $statement=$this->environment->db->execute('SELECT source_id,reason FROM '.$this->environment->db->table('historical_source_markers').' WHERE tenant_id=:tenant AND run_id=:run ORDER BY source_id,reason',['tenant'=>$run->tenant->value,'run'=>$run->value]);$out=[];
        while (($r=$statement->fetch(\PDO::FETCH_ASSOC))!==false) { $out[]=['source_id'=>Connection::text($r,'source_id'),'reason'=>Connection::text($r,'reason')]; }return $out;
    }
    /**
     * @param array<array-key,MessageRef> $sources
     * @param array<array-key,RunId> $runs
     */
    private function registerInside(Ref $derived,DerivedKind $kind,array $sources,array $runs): void
    {
        if (!array_is_list($sources) || $sources===[] || count($sources)>4096 || !array_is_list($runs) || count($runs)>64) { throw new \InvalidArgumentException('Bounded dependencies required'); }
        $db=$this->environment->db;$refs=[];
        foreach ($sources as $s) { $derived->tenant->assertSame($s->ref->tenant);$this->pin($s);$refs[]=$s->toArray(); }
        $hash=$this->hash($derived,'derived-sources',$refs);$key=['tenant'=>$derived->tenant->value,'id'=>$derived->value];
        $db->execute('INSERT INTO '.$db->table('derived_refs').' VALUES(:tenant,:id,:kind,:hash,NULL) ON CONFLICT DO NOTHING',$key+['kind'=>$kind->value,'hash'=>$hash]);
        $r=$db->row('SELECT kind,source_hash,invalid_reason FROM '.$db->table('derived_refs').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',$key);
        if ($r===null || Connection::text($r,'kind')!==$kind->value || Connection::text($r,'source_hash')!==$hash) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
        if ($r['invalid_reason']!==null) { throw new ContractViolation(ErrorCode::StaleContext); }
        foreach ($sources as $s) {
            $tombstone=$db->row('SELECT reason FROM '.$db->table('source_tombstones').' WHERE tenant_id=:tenant AND source_id=:source AND (reason<>\'source_corrected\' OR source_version=:version) LIMIT 1',
                ['tenant'=>$key['tenant'],'source'=>$s->ref->value,'version'=>$s->version]);
            if ($tombstone!==null) { throw new ContractViolation(ErrorCode::StaleContext); }
            $db->execute('INSERT INTO '.$db->table('source_edges').' VALUES(:tenant,:id,:source,:version) ON CONFLICT DO NOTHING',$key+['source'=>$s->ref->value,'version'=>$s->version]);
        }
        foreach ($runs as $run) { $derived->tenant->assertSame($run->tenant);$db->execute('INSERT INTO '.$db->table('derived_runs').' VALUES(:tenant,:id,:run) ON CONFLICT DO NOTHING',$key+['run'=>$run->value]); }
    }
    private function pin(MessageRef $s): void
    {
        $db=$this->environment->db;$key=['tenant'=>$s->ref->tenant->value,'source'=>$s->ref->value,'version'=>$s->version];
        // Serialize registration against invalidation on the same source identity.
        $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>json_encode([$key['tenant'],$key['source']],JSON_THROW_ON_ERROR)]);
        $db->execute('INSERT INTO '.$db->table('history_source_pins').' VALUES(:tenant,:source,:version,:branch,:turn,:hash) ON CONFLICT DO NOTHING',$key+['branch'=>$s->branch->value,'turn'=>$s->turn,'hash'=>$s->hash->value]);
        $r=$db->row('SELECT branch,turn,content_hash FROM '.$db->table('history_source_pins').' WHERE tenant_id=:tenant AND source_id=:source AND source_version=:version',$key);
        if ($r===null || Connection::text($r,'branch')!==$s->branch->value || Connection::number($r,'turn')!==$s->turn || Connection::text($r,'content_hash')!==$s->hash->value) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
    }
    private function authorize(ExecutionContext $context,Ref $scope,string $operation): void
    { $context->session->tenant->assertSame($scope->tenant);$this->environment->authorize($context);$this->authority->authorize($context,$scope,$operation); }
    private function lock(Ref $branch,string $kind): void
    { $this->environment->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>json_encode([$branch->tenant->value,$branch->value,$kind],JSON_THROW_ON_ERROR)]); }
    /** @return array{tenant:string,branch:string} */
    private function key(Ref $branch): array { return ['tenant'=>$branch->tenant->value,'branch'=>$branch->value]; }
    private function hash(Ref $scope,string $domain,mixed $value): string { return $this->environment->hasher->hash($scope->tenant,$domain,$value)->value; }
}
