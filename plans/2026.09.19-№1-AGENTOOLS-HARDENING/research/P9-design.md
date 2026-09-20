# P9 design — least-privilege CI and exact private release-candidate evidence

**Date:** 2026-09-19  
**Tree anchor:** `445a8b29769fb918c846545190304494b1483981` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P9-design-rag.md`.

## Design question

How can Agentools automate P1–P8 evidence and construct exact, reproducible PHP/Dart private
release subjects with SBOM and provenance, while keeping untrusted code outside privileged contexts,
not promising unavailable GitHub features, and preserving P10/G-RELEASE ownership?

## Repository and live capability observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| The only workflow runs four conformance layers on Ubuntu/PHP 8.4 and references `actions/checkout@v4` and `shivammathur/setup-php@v2`. It declares no explicit permissions, timeout or concurrency policy. | `.github/workflows/ci.yml` | P9 needs a structural policy checker and full-SHA/minimum-permission rewrite before expanding jobs. |
| PHP declares `>=8.3`; both Dart packages declare SDK `^3.11.0` and `publish_to: none`. | package manifests | Exact supported matrices must come from executed P1.3, while lowest/latest resolution is disposable evidence rather than candidate input. |
| Dart MCP companion depends on `../agentools` by path. | `dart/agentools_mcp_dart/pubspec.yaml`, D12, P7.4 | It may be a private paired subject, but stays public-release-blocked until P10 resolves publication topology. |
| P7.4 owns exact file/content manifests and clean consumers but excludes deterministic bytes, SBOM and provenance. | `phases/P7/P7.4.md` | P9 consumes P7 selection as an oracle and owns normalized archive serialization. |
| P8 defines `focused`, `bounded`, `extended` and `release-candidate` evidence tiers and fingerprint-bound timing. | D13, P8.1, P8.5 | P9 maps exact IDs/commands to events; it cannot redefine proof or turn fingerprint mismatch into pass. |
| Repository is private; default workflow token setting was `write`, Actions were unrestricted, main was unprotected, no environments existed, and inspected GitHub security-analysis features were disabled at design time. | read-only `gh api` observations, 2026-09-19 | Contract starts at `permissions: {}` and reports capabilities explicitly. Design does not mutate GitHub settings or assume protected-ref/attestation availability. |
| Current manifests have locks, but library consumer dependency resolution is not controlled by the library root lock. | manifests plus Composer primary docs in RAG | CI needs both exact-lock and fresh lowest/latest consumer-style lanes; RC remains bound to declared frozen inputs. |

## Accepted model

### 1. Two trust planes

Untrusted PR CI has no secrets or write scopes and never crosses `pull_request_target` or privileged
`workflow_run` with fork-controlled checkout/artifacts/caches. Trusted private RC is separately
triggered from an allowed ref, consumes only repository-owned frozen inputs and grants OIDC/
attestation permissions only in an optional final job. All workflows start at `permissions: {}`.

Capabilities are data: `available-enabled`, `available-disabled`, `unavailable-plan` or `unknown`.
The plan can remain locally executable when GitHub Enterprise-only private attestations are
unavailable, but its receipt cannot claim hosted attestation.

### 2. Evidence tiers are mapped, not reinvented

PR receives `focused` plus bounded critical semantic/security checks; main receives `bounded`;
scheduled/manual receives `extended`; trusted private RC alone consumes `release-candidate`. PHP
and Dart supported runtimes come from P1.3. Lowest/latest jobs resolve in clean disposable copies
(Composer `--prefer-lowest --prefer-stable` and Dart `pub downgrade`) and never alter or supply RC
locks. P8 timing is blocking only on an exact reviewed fingerprint.

Pinned OSV-Scanner covers Composer and Dart lockfiles. Pinned Gitleaks covers history and the
worktree with redacted findings. GitHub-native dependency/secret features are additional capability
rows, not assumed substitutes or silent prerequisites.

### 3. Exact subjects precede metadata

P9 builds three private subjects: PHP package, Dart core package and Dart MCP companion paired
package. A repository-owned normalized tar+gzip algorithm fixes path order, owner/group, modes,
mtime, PAX headers and gzip name/time. `SOURCE_DATE_EPOCH` is derived from the source commit.
Two clean build roots with no shared assembly cache must produce identical content manifests and
SHA-256 archive bytes.

Composer archive and Dart publish preview are content/package oracles only. Primary documentation
does not promise deterministic bytes, and Dart does not provide a supported command to export the
exact hosted-package tarball. P9 therefore makes no registry-byte-identity claim.

### 4. SBOM, provenance and attestation stay distinct

SPDX 2.3 JSON is the sole normative SBOM per exact subject. Syft is pinned and separately catalogs
source/lock inputs and extracted subject contents; official SPDX validation plus repository
reconciliation closes generator-only trust. CycloneDX is optional and non-normative until a named
consumer requires it.

SLSA v1.2 provenance names exact archive SHA-256 subjects and frozen materials. A locally generated
and verified statement is not a platform attestation. Current GitHub guidance uses the unified
`actions/attest` action; it is full-SHA pinned and gets `id-token: write` and
`attestations: write` only when private-repository capability is positively available. Otherwise
the aggregate records the precise unavailable/not-executed state.

### 5. P10 receives a candidate, not a publication

The private-RC workflow has finite timeouts/retention/concurrency and independently rehashes a
downloaded bundle. Workflow artifact ID/digest proves transport, not release-file identity.
The aggregate receipt exact-joins source/tree, contracts, tool lock, matrices, P8 evidence,
subjects, SBOMs, provenance and hosted status. It contains no tag, registry upload, public version
decision or credentials. Those remain D5/P10/G-RELEASE.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P9.1 | trust contract, immutable tool lock, policy checker and capability baseline | terminal P1/P7/P8 inputs | One security owner before workflows or release jobs acquire permissions. |
| P9.2 | hardened CI/extended workflows and exact matrix/tier map | P9.1 + P1/P8 | CI automation can be reviewed without release-subject or signing concerns. |
| P9.3 | deterministic subjects, checksums and receipt schema | P7.4 + P9.1 | Exact bytes must stabilize before SBOM/provenance bind them. |
| P9.4 | SPDX/provenance and conditional platform attestation evidence | P9.3 + capability baseline | Metadata/signing state must not contaminate reproducible subject bytes. |
| P9.5 | trusted workflow, independent verifier and aggregate receipt | P8.5 + P9.2–P9.4 | Final exact-set assembly waits for immutable child evidence. |

```text
P1/P7/P8 -> P9.1 -> P9.2 -------\
          P7.4 -> P9.3 -> P9.4 ---> P9.5 -> P10
                   P8.5 ---------/
```

## Alternatives rejected

### A. Use action major tags because they are conventional

Rejected. GitHub states that only a full-length commit SHA is immutable. Human-readable version
comments and an update bot preserve maintainability without trading away reference integrity.

### B. Run privileged `pull_request_target` and then checkout the PR

Rejected. It combines write/secrets context with untrusted code and is an explicitly documented
dangerous pattern.

### C. Treat Composer/Dart native packaging as deterministic byte producers

Rejected. They remain useful content validation, but the checked docs do not define normalized
tar/gzip bytes. Repository-owned serialization gives a testable promise.

### D. Emit both SPDX and CycloneDX as equal canonical formats

Rejected. Two normative graphs invite drift with no current consumer benefit. SPDX 2.3 is canonical;
a CycloneDX derivative may be added for a named tool without changing release identity.

### E. Require GitHub attestation for P9 completion

Rejected. Private-repository availability depends on GitHub plan/capability. P9 preserves locally
verifiable SBOM/provenance and records the hosted limitation; it never fabricates attestation.

### F. Upload to a private package registry as the proof

Rejected. Upload credentials and registry state enlarge the side-effect boundary without improving
byte reproducibility. Local/private workflow artifacts are sufficient for P9; P10 owns release.

## Validation strategy

1. Mutate policy fixtures to prove permissions, pins, event/ref, cache/artifact and timeout failures.
2. Generate support/lowest/latest matrices and verify exact equality with P1/P8 contracts.
3. Build all subjects twice under different roots/umasks/locales and compare manifests plus bytes.
4. Corrupt archive entries, SBOM relationships, subject digests, provenance materials and receipts.
5. Verify local statements offline and platform attestations only when capability is proven.
6. Download/re-hash a private candidate independently and exact-join all child evidence.
7. Assert no workflow contains public tag/release/registry operations or publication credentials.

## Risks retained

- A private repository without Enterprise Cloud may not support GitHub artifact attestations; the
  local SBOM/provenance evidence remains useful but is explicitly not a hosted attestation.
- Main is currently unprotected and no GitHub environments exist. P9 can encode fail-closed guards
  and report the gap, but changing repository governance is outside this design/execution scope.
- Scanner databases and advisory feeds change. Tool/database timestamps and digests are retained;
  a clean scan is point-in-time evidence, not absence of all vulnerabilities.
- The MCP companion path dependency blocks public Dart publication until P10 resolves package
  topology; P9 intentionally builds it only as a paired private subject.
