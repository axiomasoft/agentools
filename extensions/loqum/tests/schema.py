#!/usr/bin/env python3
"""Executable additive voice schema positives and negatives; no acoustic evidence."""
import copy
from datetime import datetime
import json
from pathlib import Path
import sys
import unittest
from jsonschema import Draft202012Validator, FormatChecker

ROOT = Path(__file__).resolve().parents[3]
FIXTURES = ROOT/'fixtures/platform/v1/voice'
SCHEMA = json.loads((FIXTURES/'final-utterance.schema.json').read_text())
SAMPLE = json.loads((FIXTURES/'final-utterance.json').read_text())
FORMATS = FormatChecker()


@FORMATS.checks('date-time', raises=ValueError)
def utc_calendar(value):
    # jsonschema's RFC3339 format dependency is optional. Never silently skip calendar validation.
    if isinstance(value, str):
        datetime.fromisoformat(value.replace('Z', '+00:00'))
    return True


VALIDATOR = Draft202012Validator(SCHEMA, format_checker=FORMATS)


class VoiceSchemaTests(unittest.TestCase):
    def test_sample(self):
        Draft202012Validator.check_schema(SCHEMA)
        VALIDATOR.validate(SAMPLE)
        fallback = dict(SAMPLE, channel='text-fallback')
        VALIDATOR.validate(fallback)
        matrix = json.loads((FIXTURES/'live-prerequisites.json').read_text())
        self.assertEqual(matrix['live_provider'], 'not_executed')
        self.assertEqual(matrix['production_readiness'], 'not_claimed')
        self.assertEqual({lane['id'] for lane in matrix['lanes']}, {'stt', 'tts', 'acoustic', 'host-media'})
        self.assertTrue(all(lane['status'].startswith('pending_') and lane['requires'] for lane in matrix['lanes']))

    def test_interim_and_missing(self):
        for key in SAMPLE:
            changed = dict(SAMPLE)
            del changed[key]
            self.assertTrue(list(VALIDATOR.iter_errors(changed)), key)
        self.assertTrue(list(VALIDATOR.iter_errors(dict(SAMPLE, is_final=False))))

    def test_untrusted_authority(self):
        for key in ('actor_ref', 'tenant_ref', 'authorization_ref', 'capability', 'consent_ref', 'host_secret'):
            self.assertTrue(list(VALIDATOR.iter_errors(dict(SAMPLE, **{key: 'forged'}))), key)
        changed = copy.deepcopy(SAMPLE)
        changed['text_ref']['grant'] = True
        self.assertTrue(list(VALIDATOR.iter_errors(changed)))

    def test_invalid_values(self):
        for key, value in (('media_epoch', 0), ('revision', 0), ('context_revision', 2**53),
                           ('utterance_id', ''), ('channel', 'raw'), ('locale', ''),
                           ('text_hash', 'unknown'), ('started_at', '2026-02-30T09:00:00Z')):
            self.assertTrue(list(VALIDATOR.iter_errors(dict(SAMPLE, **{key: value}))), key)


NAMES = {'test_sample': 'voice.schema-sample', 'test_interim_and_missing': 'voice.schema-interim-missing',
         'test_untrusted_authority': 'voice.schema-untrusted-authority', 'test_invalid_values': 'voice.schema-invalid-values'}

if __name__ == '__main__':
    cases = []
    for method, case_id in NAMES.items():
        result = unittest.TestResult()
        VoiceSchemaTests(method).run(result)
        for _, error in result.errors + result.failures:
            sys.stderr.write(error)
        cases.append({'id': case_id, 'status': 'passed' if result.wasSuccessful() and not result.skipped else 'failed'})
    failures = sum(c['status'] != 'passed' for c in cases)
    print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':cases,
                      'count':len(cases),'failures':failures,'skips':0}))
    sys.exit(bool(failures))
