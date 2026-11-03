#!/usr/bin/env python3
"""Offline validation of a potential live probe; never invokes a provider or credentials."""
from datetime import datetime, timezone
import json
import re
from urllib.parse import urlsplit


class Refusal(ValueError):
    pass


def validate(m, now=None):
    now = now or datetime.now(timezone.utc)
    if not isinstance(m, dict) or m.get('schema') != 'agentools-live-probe-request/v1':
        raise Refusal('schema')
    for key in ('deployment_ref', 'provider', 'account_ref', 'region', 'model_revision', 'capability',
                'credential_ref', 'pricing_version', 'currency', 'authorization_ref', 'source_version'):
        if not isinstance(m.get(key), str) or not m[key].strip():
            raise Refusal(key)
    if not re.fullmatch(r'[A-Z]{3}', m['currency']):
        raise Refusal('currency')
    if not re.fullmatch(r'sha256:[a-f0-9]{64}', m.get('approved_synthetic_payload_hash', '')):
        raise Refusal('payload hash')
    if m.get('data_class') != 'synthetic' or m.get('approved') is not True:
        raise Refusal('owner data/spend authorization')
    for key in ('max_calls', 'max_input_bytes', 'max_output_bytes', 'max_elapsed_ms', 'max_cost_minor'):
        if type(m.get(key)) is not int or m[key] <= 0:
            raise Refusal(key)
    sources = m.get('official_sources')
    if not isinstance(sources, list) or not sources:
        raise Refusal('official sources')
    for source in sources:
        if not isinstance(source, dict) or source.get('kind') not in ('model', 'capability', 'price'):
            raise Refusal('source kind')
        parsed = urlsplit(source.get('url', ''))
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password:
            raise Refusal('source URL')
        try:
            checked = datetime.fromisoformat(source['checked_at'].replace('Z', '+00:00'))
            expires = datetime.fromisoformat(source['expires_at'].replace('Z', '+00:00'))
            if checked.tzinfo is None or expires.tzinfo is None or checked > now or expires <= now or expires <= checked:
                raise Refusal('stale source')
        except (KeyError, TypeError, ValueError) as exc:
            raise Refusal('invalid/stale source date') from exc
    if {s['kind'] for s in sources} != {'model', 'capability', 'price'}:
        raise Refusal('incomplete official evidence')
    return {'status': 'input_complete_unverified', 'actual_provider_call': False,
            'qualification': 'not_run', 'limitations': [
                'URL ownership, source content and exact model/capability/pricing require operator primary-source verification.',
                'This offline check is not authorization, live execution or qualification.']}


if __name__ == '__main__':
    import argparse
    import sys
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest')
    args = parser.parse_args()
    try:
        with open(args.manifest, encoding='utf-8') as stream:
            print(json.dumps(validate(json.load(stream))))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(json.dumps({'status': 'refused', 'reason': str(error), 'actual_provider_call': False}))
        sys.exit(1)
