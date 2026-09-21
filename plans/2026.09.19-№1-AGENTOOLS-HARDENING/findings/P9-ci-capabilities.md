# P9 — GitHub capability inventory (read-only baseline)

**Observed:** 2026-09-20 (UTC)  
**Repository:** `axiomasoft/agentools` (private)  
**Sources:** `gh api` read-only queries; no settings were changed.

Capability states: `available-enabled` · `available-disabled` · `unavailable-plan` · `unknown`

| Capability | State | Evidence | P9 consequence |
|:--|:--|:--|:--|
| Repository visibility | available-enabled (private) | `GET /repos/axiomasoft/agentools` → `private: true` | RC remains private; public release blocked until P10/G-RELEASE |
| Actions enabled | available-enabled | `actions/permissions` → `enabled: true`, `allowed_actions: all` | Policy checker required before expanding jobs |
| Default workflow token | available-enabled (**write**) | `actions/permissions/workflow` → `default_workflow_permissions: write` | Contract mandates `permissions: {}` at workflow level (SC-PERM-001) |
| SHA pinning required (org) | available-disabled | `sha_pinning_required: false` | Full-SHA policy is repository contract, not platform enforcement |
| Branch protection on `main` | available-disabled | `GET .../branches/main/protection` → HTTP 404 | P9 reports gap; governance change out of P9.1 scope |
| GitHub Environments | available-disabled | `environments` → `total_count: 0` | Trusted RC guards deferred to P9.5 |
| Artifact attestations (private) | unavailable-plan | Org plan `team` (not Enterprise Cloud) per D14/RAG | Local SBOM/provenance only; no hosted attestation claim |
| `actions/attest` | available-disabled (unused) | Lock pins SHA; no workflow job yet | Conditional job in P9.4 |
| Code scanning (CodeQL) | available-disabled | `security_and_analysis.code_security.status: disabled` | OSV/Gitleaks lanes are primary scanners |
| Secret scanning | available-disabled | `secret_scanning.status: disabled` | Gitleaks is complementary, not substitute |
| Secret scanning push protection | available-disabled | `secret_scanning_push_protection.status: disabled` | Documented separately from Gitleaks |
| Dependabot security updates | available-disabled | `dependabot_security_updates.status: disabled` | OSV lockfile scan is point-in-time |
| Fork PRs | available-disabled | `allow_forking: false` | Untrusted plane still modeled for future forks |

## Workflow policy baseline (P9.2 GREEN)

`ci.yml` and `extended.yml` satisfy SC-* (pinned SHAs, `permissions: {}`, concurrency, timeouts,
read-only job grants). Support/lowest/latest matrix and P8 tier map: `tools/ci_matrix.py` →
`artifacts/P9-ci-map.json`.

Machine receipt: `python3 tools/ci_policy.py baseline` →
`plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P9-workflow-baseline.json` (0 violations)

## Redaction

No `GITHUB_TOKEN`, secrets, or clone tokens are recorded in this finding. API responses were
summarized to enum fields only.
