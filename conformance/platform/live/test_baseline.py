import copy
import hashlib
import importlib.util
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
import sys
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(HERE))
import probe


def valid(now):
    checked = (now - timedelta(days=1)).isoformat()
    expires = (now + timedelta(days=1)).isoformat()
    return {'schema':'agentools-live-probe-request/v1','deployment_ref':'d','provider':'p',
            'account_ref':'a','region':'r','model_revision':'m','capability':'c',
            'credential_ref':'opaque:test','pricing_version':'v','currency':'USD',
            'authorization_ref':'owner-grant','source_version':'v', 'approved':True,
            'data_class':'synthetic','approved_synthetic_payload_hash':'sha256:' + 'a'*64,
            'max_calls':1,'max_input_bytes':1024,'max_output_bytes':1024,'max_elapsed_ms':1000,'max_cost_minor':1,
            'official_sources':[{'kind':k,'url':'https://example.com/'+k,'checked_at':checked,'expires_at':expires}
                                for k in ('model','capability','price')]}


class Cases(unittest.TestCase):
    def test_baseline_record(self):
        value=json.loads((ROOT/'docs/platform/baseline.json').read_text())
        self.assertEqual(value['tier'],'contract-scripted')
        self.assertEqual(value['live_provider'],'not_run')
        self.assertIsNone(value['provider_pricing'])
        self.assertIsNone(value['production_slo'])
        self.assertEqual({x['group'] for x in value['measurements']},{'golden','fault'})
        self.assertTrue(value['input_sha256'])
        for name, expected in value['input_sha256'].items():
            self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(), expected, name)
        for item in value['measurements']:
            self.assertGreater(item['test_count'],0)
            self.assertGreaterEqual(item['elapsed_ms'],0)
            self.assertEqual((item['provider_calls'],item['measured_spend_minor'],item['failures'],item['skips']),(0,0,0,0))
    def test_actual_golden_fault(self):
        import baseline
        data=baseline.benchmark()
        self.assertEqual([(x['group'],x['test_count']) for x in data['measurements']],[('golden',2),('fault',4)])
        self.assertEqual(data['readiness'],'technical-contract-only')
    def test_playbooks(self):
        doc=(ROOT/'docs/platform/incidents/README.md').read_text()
        for phrase in ('Deployment disabled','Provider outage','Data/region ban','Cache revoke',
                       'Reservation/admission/settlement unknown','owner down','Voice to text',
                       'same provider request key','same owner key','no automatic release', 'current host permission'):
            self.assertIn(phrase,doc)
    def test_probe_complete_is_not_live(self):
        out=probe.validate(valid(datetime.now(timezone.utc)))
        self.assertEqual(out['status'],'input_complete_unverified')
        self.assertFalse(out['actual_provider_call'])
        self.assertEqual(out['qualification'],'not_run')
    def test_probe_missing_binding(self):
        now=datetime.now(timezone.utc)
        for key in ('deployment_ref','provider','account_ref','region','model_revision','capability','credential_ref',
                    'pricing_version','currency','authorization_ref','source_version','approved_synthetic_payload_hash'):
            value=valid(now);del value[key]
            with self.subTest(key=key),self.assertRaises(probe.Refusal):probe.validate(value,now)
    def test_probe_data_and_spend(self):
        now=datetime.now(timezone.utc)
        for patch in ({'approved':False},{'data_class':'private'},{'max_cost_minor':0},
                      {'max_calls':True},{'max_input_bytes':-1},{'max_elapsed_ms':None}):
            value=valid(now);value.update(patch)
            with self.subTest(patch=patch),self.assertRaises(probe.Refusal):probe.validate(value,now)
    def test_probe_sources_stale_or_missing(self):
        now=datetime.now(timezone.utc)
        for index,field,value in [(0,'expires_at',(now-timedelta(seconds=1)).isoformat()),
                                  (1,'checked_at',(now+timedelta(days=1)).isoformat()),
                                  (2,'url','http://host/price'),(2,'kind','model')]:
            item=valid(now);item['official_sources'][index][field]=value
            with self.subTest(field=field),self.assertRaises(probe.Refusal):probe.validate(item,now)
    def test_probe_never_resolves_credentials(self):
        value=valid(datetime.now(timezone.utc));value['credential_ref']='opaque:must-not-be-read'
        self.assertFalse(probe.validate(value)['actual_provider_call'])


if __name__ == '__main__': unittest.main()
