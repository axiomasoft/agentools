"""Voice-local evidence qualification, not a generic evaluation service or live certificate."""
import hashlib
import json
from pathlib import Path
import re

HERE=Path(__file__).resolve().parent
CASES=tuple(json.loads((HERE/'case-map.json').read_text()).values())
TUPLE_FIELDS={'deployment_ref','model_revision','profile_ref','profile_bundle_hash','task_ref','suite_hash',
              'prompt_hash','skills_hash','tool_schema_hash','material_corpus_hash'}
CORPUS='examples/platform/voice/events.json'

def digest(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()

def runtime_tuple(report,source_hashes):
    observed=report.get('observations',{}).get('voice-journey.runnable-example',{}).get('runnable_example',{})
    tuple_=dict(observed.get('tuple',{}))
    tuple_['suite_hash']='sha-256:'+digest(source_hashes)
    return tuple_

def assess(report,tuple_,source_hashes):
    fixture=json.loads((HERE/'qualification-fixture.json').read_text())
    reasons=[]
    if (set(tuple_)!=TUPLE_FIELDS or any(not isinstance(v,str) or not v for v in tuple_.values())
        or any(re.fullmatch(r'sha-256:[a-f0-9]{64}',v) is None for k,v in tuple_.items() if k.endswith('_hash'))):
        reasons.append('invalid_exact_tuple')
    if not source_hashes or tuple_!=runtime_tuple(report,source_hashes):reasons.append('tuple_not_bound_to_observed_runtime')
    if tuple_.get('material_corpus_hash')!='sha-256:'+source_hashes.get(CORPUS,''):reasons.append('corpus_not_bound')
    cases=report.get('cases',[])
    ids=[c.get('id') for c in cases if isinstance(c,dict)]
    if len(ids)!=len(CASES) or set(ids)!=set(CASES):reasons.append('required_case_set_differs')
    if not cases or any(c.get('status')!='passed' for c in cases):reasons.append('required_case_failed_or_skipped')
    if (report.get('mode')!='isolated-postgres' or report.get('tier')!='isolated-postgres'
        or report.get('count')!=len(CASES) or report.get('failures')!=0 or report.get('skips')!=0):reasons.append('invalid_runtime_evidence')
    isolation=report.get('isolation',{})
    if (isolation.get('engine')!='postgresql' or isolation.get('isolated') is not True
        or isolation.get('allow_marker_verified') is not True or set(isolation.get('case_ids',[]))!=set(CASES)
        or len(isolation.get('case_ids',[]))!=len(CASES)
        or not isinstance(isolation.get('database'),str) or not isolation['database'].startswith('agentools_test_')
        or not isinstance(isolation.get('owner'),str) or not isolation['owner']):
        reasons.append('isolation_missing')
    observations=report.get('observations',{})
    if set(observations)!=set(CASES):reasons.append('observations_missing')
    if any(type(o.get('model_calls')) is not int or o.get('model_calls')!=o.get('native_invocations') for o in observations.values()):
        reasons.append('duplicate_or_unaccounted_model_io')
    return {'format':fixture['format'],'scope':fixture['technical_scope'],
            'decision':'qualified' if not reasons else 'rejected','tuple':tuple_,'reasons':reasons,
            'source_manifest_sha256':digest(source_hashes),'runtime_report_sha256':digest(report),
            'evidence_tier':'local-host-e2e','required_cases':list(CASES),
            'domain_qualification':{'decision':'insufficient_evidence','prerequisites':fixture['external_prerequisites']},
            'live_provider':'not_executed','production_readiness':'not_claimed','rollout_policy_ref':fixture['rollout_policy_ref']}

def applicable(certificate,current_tuple):
    return (certificate.get('decision')=='qualified' and certificate.get('scope')=='technical-contract-only'
            and certificate.get('tuple')==current_tuple and set(current_tuple)==TUPLE_FIELDS)
