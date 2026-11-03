#!/usr/bin/env python3
"""Synthetic playout schema validation with negative examples."""
import json
from pathlib import Path
import sys
from jsonschema import Draft202012Validator

ROOT=Path(__file__).resolve().parents[4]
FIXTURES=ROOT/'fixtures/platform/v1/voice'
SCHEMA=json.loads((FIXTURES/'playout-receipt.schema.json').read_text())
SAMPLE=json.loads((FIXTURES/'playout-receipt.json').read_text())

def run():
    Draft202012Validator.check_schema(SCHEMA)
    validator=Draft202012Validator(SCHEMA)
    validator.validate(SAMPLE)
    for key in SAMPLE:
        changed=dict(SAMPLE);del changed[key]
        if not list(validator.iter_errors(changed)): raise RuntimeError('Required field accepted as missing: '+key)
    for change in ({'status':'unknown'},{'evidence_level':'generated'},{'media_epoch':0},{'response_version':0},
                   {'delivered_offset':None},{'delivered_offset':-1},{'offset_unit':'audio_ms'},
                   {'understanding_claimed':True},{'displayed_offset':13}):
        if not list(validator.iter_errors(dict(SAMPLE,**change))): raise RuntimeError('Invalid receipt accepted')
    return {'mode':'contract-scripted','tier':'contract-scripted',
            'cases':[{'id':'voice-delivery.schema','status':'passed'}],'count':1,'failures':0,'skips':0}

if __name__=='__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)}));sys.exit(1)
