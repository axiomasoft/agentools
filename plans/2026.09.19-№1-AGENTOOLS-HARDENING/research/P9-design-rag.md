# P9 design RAG — CI, reproducible artifacts, SBOM and provenance

**Verified:** 2026-09-19  
**Retrieval:** Perplexity deep research, four broad web+academic searches and targeted follow-up
queries were used to maximize candidate/failure discovery. Every load-bearing claim below was
rechecked in a primary source. Perplexity surfaced older specialized GitHub actions
(`attest-build-provenance`/`attest-sbom`); current GitHub documentation corrected the design to the
unified `actions/attest` action.

| Evidence | Verified claim | P9 consequence |
|:--|:--|:--|
| RAG:✅ [GitHub secure use](https://docs.github.com/en/actions/reference/security/secure-use), accessed 2026-09-19 | Least privilege is recommended; `pull_request_target`/`workflow_run` plus untrusted checkout/artifacts is dangerous; only a full-length commit SHA is immutable for an action reference. | `permissions: {}`, job-minimum grants, no untrusted privilege transition, and 40-hex pins are policy gates. |
| RAG:✅ [GitHub workflow permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions), accessed 2026-09-19 | Permissions can be set workflow/job-wide; unspecified permissions become none when any are specified. | Deny by default and grant `contents: read` or attestation scopes only at the owning job. |
| RAG:✅ [GitHub artifact attestations](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations), accessed 2026-09-19 | Current examples use `actions/attest@v4`; private/internal repository attestations require GitHub Enterprise Cloud; the job needs `id-token: write`, `contents: read` and `attestations: write`. SBOM can be bound to a subject path. | Hosted attestation is conditional capability; use the current unified action at a reviewed full SHA and keep local statement status separate. |
| RAG:✅ [`actions/attest-sbom` repository](https://github.com/actions/attest-sbom), accessed 2026-09-19 | The specialized action is deprecated in favor of `actions/attest` and accepted SPDX/CycloneDX JSON. | Do not institutionalize the deprecated action surfaced by secondary synthesis. |
| RAG:✅ [Composer CLI](https://getcomposer.org/doc/03-cli.md), [schema archive rules](https://getcomposer.org/doc/04-schema.md#archive), accessed 2026-09-19 | `validate --strict` is available; `--prefer-lowest` is intended to test minimum requirements and is generally paired with `--prefer-stable`; archive exclusions follow documented patterns. No deterministic-byte guarantee is stated. | Add disposable lowest/latest lanes and use native archive behavior only as a content oracle. |
| RAG:✅ [Composer libraries](https://getcomposer.org/doc/02-libraries.md), accessed 2026-09-19 | A library's root lock is not installed for its consumers. | Test exact project locks and fresh consumer-style resolution separately; never present root-lock success as supported-range proof. |
| RAG:✅ [Dart publishing](https://dart.dev/tools/pub/publishing), [`pub downgrade`](https://dart.dev/tools/pub/cmd/pub-downgrade), accessed 2026-09-19 | Dry-run validates and lists package contents without upload; publishable packages require hosted dependencies; downgrade resolves lowest direct/transitive versions and regenerates the lock. No supported exact publish-tar export is documented. | Use fresh disposable downgrade/latest jobs, keep path-dependent MCP package public-blocked and own canonical private bytes. |
| RAG:✅ [SOURCE_DATE_EPOCH](https://reproducible-builds.org/specs/source-date-epoch/), [stable archives](https://reproducible-builds.org/docs/archives/), accessed 2026-09-19 | Reproducible archives require normalized ordering, mtimes, owners/groups, modes/PAX metadata and gzip name/time; source epoch provides a deterministic timestamp. | P9.3 defines repository-owned normalized tar+gzip and proves it in two independent builds. |
| RAG:✅ [SPDX 2.3 conformance](https://spdx.github.io/spdx-spec/v2.3/conformance/), [SPDX tools-python](https://github.com/spdx/tools-python), accessed 2026-09-19 | SPDX defines required document/package relationships and has official validation tooling. | Use SPDX 2.3 JSON as one normative format plus independent reconciliation. |
| RAG:✅ [Syft package catalogers](https://oss.anchore.com/docs/capabilities/all-packages/), accessed 2026-09-19 | Syft advertises Composer lock and Dart pubspec/lock catalogers. | Pin Syft and scan source/lock inputs separately from extracted exact subjects; do not equate the two inventories. |
| RAG:✅ [SLSA provenance v1.2](https://slsa.dev/spec/v1.2/provenance), accessed 2026-09-19 | Provenance has explicit subjects, build definition, run details and materials. | Bind statements to exact archive digests and frozen input/tool identities. |
| RAG:✅ [OSV-Scanner supported lockfiles](https://google.github.io/osv-scanner/supported-languages-and-lockfiles/), [usage](https://google.github.io/osv-scanner/usage/), accessed 2026-09-19 | OSV-Scanner explicitly supports `composer.lock` and `pubspec.lock`. | Pin it and scan all three locks as a separate point-in-time vulnerability lane. |
| RAG:✅ [Gitleaks repository](https://github.com/gitleaks/gitleaks), accessed 2026-09-19 | Gitleaks supports repository/history and directory scans and configurable redaction. | Add pinned history/worktree scanning, while keeping GitHub push protection as a distinct capability. |

## Perplexity contribution and corrections

Perplexity was used across CI trust, dependency matrices, archive determinism, SPDX/CycloneDX,
SLSA, GitHub plan constraints, Syft, OSV and Gitleaks. It usefully exposed:

1. the need to separate untrusted change CI from trusted RC assembly;
2. the circularity risk of embedding SBOM/provenance in the subject they identify;
3. the mismatch between workflow artifact digests and release-file digests;
4. the difference between source/lock SBOM and exact extracted-artifact inventory;
5. licensed-feature absence as a first-class state rather than a skipped check.

Primary-source verification made three material corrections: current GitHub attestation examples use
`actions/attest`, not the older specialized actions; private/internal attestation requires Enterprise
Cloud; and neither Composer archive nor Dart publish tooling documents deterministic release bytes.

## Facts intentionally not promoted

- A full-SHA action pin controls reference mutability, not the action's correctness; review and
  update provenance remain required.
- An SBOM is inventory, not proof that dependencies are safe or that the runtime used only them.
- A vulnerability scan is time/database-dependent and cannot establish absence of unknown defects.
- SLSA provenance identifies how a subject was produced; it does not prove application semantics.
- GitHub artifact transport digests are not interchangeable with the SHA-256 of package subjects.
- GitHub secret scanning/push protection and private attestations are not assumed available merely
  because Actions itself is enabled.
