# P9.2 CI matrix and P8 tier map

**Generated:** machine output from `tools/ci_matrix.py`
**Contract:** `plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/compatibility-support-v1.md`

## Matrix rows

PHP supported: 8.3, 8.4 · disposable lowest/latest lanes never mutate source locks.
Dart supported SDK: 3.11.0, 3.13.4 for `dart_core` and `dart_mcp`.

## P8 tier coverage

- **focused** — events `pull_request`; AV ids `18`; timing `matched`.
- **bounded** — events `push`; AV ids `17`; timing `matched`.
- **extended** — events `schedule, workflow_dispatch`; AV ids `14`; timing `matched`.

## Capability gaps (not silently passed)

- GitHub dependency review and hosted attestations remain unavailable on current plan.
- Secret scanning push protection disabled; Gitleaks is complementary.

Machine map: `plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P9-ci-map.json`
