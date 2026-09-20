---
id: D14
date: 2026-09-19
status: accepted
item: P9
items: [P9, P9.1, P9.2, P9.3, P9.4, P9.5]
supersedes: []
superseded_by: null
---
# D14 — Separate untrusted CI, exact private subjects and capability-qualified attestations

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository/live read-only recon in `research/P9-design.md`; Perplexity synthesis plus RAG:✅ primary sources in `research/P9-design-rag.md`

## Решение

P9 has two non-crossing trust planes. Untrusted PR/change CI receives no secrets or write token,
does not execute fork code under privileged events and cannot feed caches/artifacts into trusted
release assembly. Trusted private RC consumes only frozen repository-owned inputs. Every workflow
starts with `permissions: {}`, grants per-job minimums and pins every third-party action by reviewed
full commit SHA; CLI tools/images are checksum/digest locked.

P8 proof tiers are mapped exactly: focused/required bounded on PR, bounded on main, extended on
schedule/manual and release-candidate only in trusted private RC. PHP/Dart supported versions come
from P1; lowest/latest resolutions run in disposable copies and never supply candidate locks.

Canonical release subjects are repository-owned normalized tar+gzip archives for PHP, Dart core
and the private-paired Dart MCP companion. P7 manifests define selected contents; P9 fixes order,
metadata and compression and requires byte-identical independent clean builds. Native package
commands remain content/validity oracles and do not define deterministic registry bytes.

SPDX 2.3 JSON is the single normative SBOM format. Source/lock and extracted-subject inventories
stay separate and are reconciled. SLSA v1.2 provenance binds exact archive digests and materials.
Current unified `actions/attest` is used only at a reviewed full SHA and only when private-repository
capability is positively proved. Local verified provenance is not called an attestation; unavailable,
disabled, unknown and not-executed hosted states remain distinct.

Workflow artifact identity is transport evidence, never release-file identity. P9 builds and
verifies a private candidate only. It does not change GitHub settings, publish registries, create a
tag/release, settle license/public identity or use publication credentials; D5/P10/G-RELEASE retain
those decisions.

## Почему

Mixing untrusted code with privileged workflow contexts makes pinning and scanners irrelevant.
Likewise, signing a non-reproducible or ambiguously selected file only authenticates ambiguity.
Exact subjects must precede SBOM/provenance, and hosted capabilities must be observed rather than
assumed. This design preserves useful local/private evidence on the current repository plan without
manufacturing a GREEN platform-attestation claim.

## Consequences

P9 has five items: trust/tool/capability contract; hardened CI matrices; deterministic exact subjects;
SBOM/provenance/conditional attestation; and trusted aggregate private RC. P10 receives exact digests
and capability states and may reject a release for a missing hosted or governance requirement.
Public release remains impossible without G-RELEASE, and the Dart MCP companion remains
public-release-blocked while its path dependency persists.
