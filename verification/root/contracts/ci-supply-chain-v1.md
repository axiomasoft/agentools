# CI and supply-chain contract v1 — AgenTools hardening (PLAN1)

**Version:** v1  
**Status:** draft contract (P9.1)  
**Evidence date:** 2026-09-20  
**Decision anchor:** D14 · **Guarantee:** G-CI-001 (workflows comply; aggregate RC evidence in P9.5)

This contract is the single normative source for untrusted change CI and trusted private release-candidate
assembly. It does not mutate GitHub repository settings, enable paid features, or publish artifacts.

## 1. Trust planes

| Plane | Trigger (normative) | Token / secrets | Code executed | Cache / artifact flow |
|:--|:--|:--|:--|:--|
| Untrusted change | `pull_request` from fork or same-repo PR without trusted RC label | No repository secrets; `permissions` default deny; job grants read-only minimum | Default branch workflows only; never `pull_request_target` checkout of untrusted head | Must not upload artifacts consumed by trusted RC |
| Trusted private RC | `workflow_dispatch` / protected `push` to allowed ref (P9.5) | OIDC and attestation scopes only in optional final job | Repository-owned frozen inputs only | May produce RC bundle; transport digest ≠ subject digest |

Cross-plane transitions via `pull_request_target`, privileged `workflow_run`, or fork-controlled
cache/artifact restore are forbidden (SC-XPL-001).

## 2. Event-to-trust-plane matrix

| GitHub event | Plane | Required guards |
|:--|:--|:--|
| `pull_request` (fork) | Untrusted | `permissions: {}` at workflow root; no `secrets.*`; no write scopes |
| `pull_request` (same repo) | Untrusted until P9.2 labels | Same as fork until explicit trusted-PR policy |
| `push` to `main` | Bounded CI (P9.2) | Explicit permissions; pinned actions |
| `schedule` / `workflow_dispatch` | Extended or RC per job map | Ref guard + concurrency |
| `pull_request_target` | **Forbidden** for checkout/build of PR head | SC-EVT-001 |
| `workflow_run` with untrusted checkout | **Forbidden** | SC-EVT-002 |

## 3. Workflow hardening requirements

Every workflow YAML under `.github/workflows/` MUST satisfy:

| Rule ID | Requirement |
|:--|:--|
| SC-PERM-001 | Top-level `permissions: {}` (explicit empty deny-by-default) |
| SC-PERM-002 | Job-level grants are minimum scopes only; no workflow-wide `write-all` |
| SC-PIN-001 | Third-party `uses:` references are `owner/repo@<40-hex-sha>` with human version comment on preceding line |
| SC-PIN-002 | No mutable refs (`@v*`, `@main`, `@master`, branch names) on third-party actions |
| SC-TIME-001 | Each job declares `timeout-minutes` |
| SC-CONC-001 | Workflow declares `concurrency` with `cancel-in-progress` where applicable |
| SC-RET-001 | Jobs uploading artifacts set `retention-days` ≤ 14 (RC jobs may tighten further in P9.5) |
| SC-CHK-001 | `actions/checkout` sets `persist-credentials: false` unless a documented exception names the job |
| SC-CONTAINER-001 | `container.image` uses digest pin (`@sha256:…`) not mutable tag |
| SC-EXPR-001 | No dynamic action ref from `${{ }}` expressions |
| SC-REUSE-001 | Reusable workflows and `workflow_call` inherit the same pin and permission rules |

Log redaction: workflows MUST NOT echo `secrets.*`, `GITHUB_TOKEN`, or scanner findings with raw secret
material (Gitleaks redact config is mandatory companion).

## 4. Immutable tool lock

Normative roster: `.ci/supply-chain-lock.json` (`agentools.supply-chain-lock/v1`).

- GitHub Actions entries: `id`, `ref_human`, `ref_immutable`, `source`, `license`.
- CLI tools (Syft, OSV-Scanner, Gitleaks): `version`, `artifact`, `download_url`, `sha256`, `license`.
- Workflows MUST NOT reference actions or CLI versions outside the lock without updating the lock and contract review.

## 5. Scanner configuration

| Tool | Config | Scope |
|:--|:--|:--|
| OSV-Scanner | `.osv-scanner.toml` | `php/composer.lock`, `dart/agentools/pubspec.lock`, `dart/agentools_mcp_dart/pubspec.lock` |
| Gitleaks | `.gitleaks.toml` | Repository history + worktree; redacted output |

GitHub-native dependency review, secret scanning, and push protection are capability rows in
`findings/P9-ci-capabilities.md`, not silent prerequisites.

## 6. Policy enforcement

Machine checker: `tools/ci_policy.py`.

- `python3 tools/ci_policy.py check-workflows` — structural YAML policy on `.github/workflows/**`
- `python3 tools/ci_policy.py check-lock` — lock schema and immutable ref shape
- `python3 tools/ci_policy.py baseline` — honest current-workflow violation report (RED before P9.2)
- `python3 tools/ci_policy.py test` — adversarial fixtures

The checker is read-only: no GitHub mutations, no secret values in output.

## 7. P8 evidence tier mapping (preview for P9.2)

| Tier | Carrier event | Owned by |
|:--|:--|:--|
| focused + bounded critical | untrusted PR | P9.2 CI workflow |
| bounded | `push` main | P9.2 |
| extended | schedule / manual | P9.2 |
| release-candidate | trusted private RC only | P9.5 |

Fingerprint-bound timing from P8.5 remains informational unless fingerprint matches reviewed baseline.

## 8. Explicit non-goals (P9.1)

- No workflow file edits (baseline stays RED honestly).
- No org/repo setting changes, environment creation, or branch protection mutation.
- No SBOM/provenance production (P9.4) or RC aggregate (P9.5).
- No publication credentials, tags, or registry uploads.
