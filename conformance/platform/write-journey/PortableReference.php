<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

/** Independent reference for this declared portable subset. Existing full reference parity remains a separate gate. */
final class PortableReference
{
    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    public static function run(array $case): array
    {
        if ($case['kind']==='confirmation') {
            /** @var array<string,mixed> $change */ $change=$case['change'];
            $accepted=!($change['replay']??false) && !($change['consume_after_denial']??false)
                && ($change['elapsed_seconds']??0)<300 && in_array($change['proof_source']??'ui',['ui','voice'],true);
            foreach (['descriptor_binding'=>'binding-1','policy_revision'=>'1','actor'=>'actor','tenant'=>'tenant-a'] as $field=>$expected) {
                if (isset($change[$field]) && $change[$field]!==$expected) { $accepted=false; }
            }
            if (isset($change['arguments']) && $change['arguments']!==$case['arguments']) { $accepted=false; }
            return ['accepted'=>$accepted];
        }
        if ($case['kind']==='retry') {
            $code=null; $calls=0;
            if ($case['capability']==='unknown') { $code='agentools.precondition.idempotency_capability_unknown'; }
            elseif ($case['changed_hash']) { $code='agentools.validation.idempotency_key_reuse'; }
            elseif ($case['elapsed_seconds']<30) { $code='agentools.conflict.idempotency_in_flight'; }
            elseif ($case['capability']==='native_key' || ($case['capability']==='read_before_write' && $case['probe']==='absent')) { $calls=1; }
            elseif ($case['capability']!=='read_before_write' || $case['probe']!=='found') { $code='agentools.precondition.idempotency_ambiguous_effect'; }
            return ['accepted'=>$code===null,'calls'=>$calls,'code'=>$code,'replay_equal'=>true];
        }
        if ($case['kind']!=='action') { throw new \RuntimeException('Unknown required reference case'); }
        $status=null; $seq=0; $epoch=0; $cancelled=false; $effects=[]; $accepted=true;
        /** @var list<array{kind:string,payload?:array<string,mixed>,epoch?:int,seq?:int,expected_prev_seq?:int}> $events */ $events=$case['events'];
        $terminal=['completed','failed','cancelled','rejected','expired'];
        $table=[
            'created'=>['action_started'=>'running','epoch_started'=>'created','cancellation_requested'=>'created','action_cancelled'=>'cancelled'],
            'running'=>['epoch_started'=>'running','effect_requested'=>'applying','confirmation_requested'=>'awaiting_confirmation','action_completed'=>'completed','action_failed'=>'failed','cancellation_requested'=>'running'],
            'applying'=>['epoch_started'=>'applying','effect_requested'=>'applying','effect_confirmed'=>'running','effect_failed'=>'running','task_binding_lost'=>'reconciliation_required','cancellation_requested'=>'applying'],
            'reconciliation_required'=>['epoch_started'=>'reconciliation_required','effect_confirmed'=>'running','effect_failed'=>'running','cancellation_requested'=>'reconciliation_required'],
        ];
        $known=['action_created','action_started','epoch_started','effect_requested','effect_confirmed','effect_failed','task_binding_lost','cancellation_requested','action_cancelled','action_completed','action_failed','confirmation_requested'];
        foreach ($events as $event) {
            $kind=$event['kind']; $effectEvent=in_array($kind,['effect_requested','effect_confirmed','effect_failed'],true);
            if (in_array($status,$terminal,true) || ($event['seq']??$seq+1)!==$seq+1 || ($event['expected_prev_seq']??$seq)!==$seq
                || ($effectEvent && (!isset($event['epoch']) || $event['epoch']<$epoch))) { $accepted=false; break; }
            if (!in_array($kind,$known,true)) { ++$seq; continue; }
            if ($status===null) { if ($kind!=='action_created') { $accepted=false; break; } $next='created'; }
            else { $next=$table[$status][$kind]??null; if ($next===null) { $accepted=false; break; } }
            if ($kind==='epoch_started') { if (!isset($event['epoch']) || $event['epoch']<=$epoch) { $accepted=false; break; } $epoch=$event['epoch']; }
            if ($kind==='effect_requested') {
                $id=$event['payload']['effect_id']??null;
                if (!is_string($id) || isset($effects[$id])) { $accepted=false; break; }
                $effects[$id]=['id'=>$id,'status'=>'requested','epoch'=>$event['epoch']];
            }
            if (in_array($kind,['effect_confirmed','effect_failed'],true)) {
                $id=$event['payload']['effect_id']??null;
                if (!is_string($id) || ($effects[$id]['status']??null)!=='requested' || $event['epoch']!==$epoch) { $accepted=false; break; }
                $effects[$id]=['id'=>$id,'status'=>$kind==='effect_confirmed'?'confirmed':'failed','epoch'=>$event['epoch']];
            }
            if ($kind==='cancellation_requested') { $cancelled=true; }
            if ($kind==='action_cancelled' && (!$cancelled || array_filter($effects,static fn (array $e): bool=>$e['status']==='requested')!==[])) { $accepted=false; break; }
            $status=$next; ++$seq;
        }
        return ['accepted'=>$accepted,'status'=>$status,'seq'=>$seq,'cancelled'=>$cancelled,
            'outcome'=>match($status){'completed'=>'succeeded','failed'=>'failed','applying','reconciliation_required'=>'unknown',default=>null},'effects'=>array_values($effects)];
    }
}
