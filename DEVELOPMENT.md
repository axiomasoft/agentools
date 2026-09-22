# Development contract

This private repository is protocol-first. A change is complete only when its
observable behavior, language implementations, shared evidence, and generated
artifacts remain aligned.

## Normative hierarchy

1. `spec/revisions/2026-09/` is the current candidate protocol revision.
2. Shared `fixtures/2026-09/` make that revision executable across the reference,
   PHP, and Dart candidates.
3. `php/`, `dart/agentools/`, and the private-paired
   `dart/agentools_mcp_dart/` implement their documented API surfaces.
4. `conformance/` and generated receipts prove observable behavior; they do not
   override the specification.
5. `spec/revisions/2026-08/` and `fixtures/2026-08/` are frozen predecessor
   evidence and may only be exercised as regression inputs.

When these layers disagree, fix the current candidate source and every affected
consumer. Do not make a gate green by weakening a fixture, skipping a check, or
expanding an allowlist.

## Change-impact matrix

| Changed surface | Required aligned work | Focused verification |
|:--|:--|:--|
| Protocol rule, schema, or fixture | Current spec, shared fixture, reference/PHP/Dart behavior, semantic profile, revision lock | `python3 tools/semantic_manifest.py --check`; `php conformance/bin/semantic-parity`; `python3 tools/ci_gate.py --all` |
| PHP SDK | PHP tests and static analysis; affected conformance | `composer test --working-dir=php`; `composer analyse --working-dir=php` |
| Dart core SDK | Dart analysis/tests; affected conformance | `(cd dart/agentools && dart analyze --fatal-infos && dart test)` |
| MCP companion or applicability | Dart MCP tests, local official runner, applicability receipt | `python3 tools/mcp_applicability.py --check`; `conformance/mcp/bin/run`; `(cd dart/agentools_mcp_dart && dart analyze --fatal-infos && dart test)` |
| CI, dependencies, or security policy | Supply-chain lock, workflow policy, matrix, OSV, Gitleaks | `python3 tools/ci_policy.py check-workflows`; `python3 tools/ci_matrix.py validate`; `python3 tools/ci_matrix.py osv-scan`; `python3 tools/ci_matrix.py gitleaks-scan` |
| Resource/evidence behavior | Both benchmark workers and owner-generated P8 receipt | `python3 tools/resource_gate.py test`; `python3 tools/resource_gate.py run` |
| Private RC inputs | Rebuild all digest-qualified release evidence locally | `python3 tools/release_candidate.py run`; `python3 tools/sbom_gate.py run`; `python3 tools/private_rc_gate.py run` |

PostgreSQL tests may run only against the repository's proven isolated test
database. Never point a mutating test at a development or production database.

Cursor registers only the fail-closed `security.safe-dirs` direct `rm`/`rmdir`
guard. It is a narrow guardrail, not a shell security sandbox. Optional memory,
telemetry, and lifecycle surfaces require separate versioned mode configuration;
when memory is enabled, its scope comes from `.serena/project.yml`
(`project:agentools`), not the checkout directory name.

## Generated files

Never hand-edit generated output. Change its source and run the owning command:

| Output | Owner command |
|:--|:--|
| `AGENTS.md`, `CLAUDE.md` | `bash scripts/build-agent-docs.sh` |
| `spec/revisions/2026-09/semantic-manifest.json` | `python3 tools/semantic_manifest.py` |
| `spec/revisions/2026-09/revision.lock.json` | `python3 tools/revision_lock.py` |
| PHP/Dart/reference projection catalogs | `python3 tools/projection_catalog.py` |
| MCP applicability matrix | `python3 tools/mcp_applicability.py` |
| Official MCP receipts | `conformance/mcp/bin/run` |
| Integrated conformance receipts | `conformance/bin/p6-gate` or `python3 tools/ci_gate.py --all` |
| P8 resource receipts | `python3 tools/resource_gate.py run` |
| Private RC/SBOM/acceptance receipts | the corresponding `tools/*_gate.py run` command |

After generation, rerun the owner in check mode where available and inspect the
diff. Generated timestamps or host fingerprints are evidence, not permission to
update unrelated baselines.

## Full acceptance

Run from a clean dependency state:

```bash
bash scripts/build-agent-docs.sh
git diff --exit-code -- spec/revisions/2026-08 fixtures/2026-08

python3 tools/ci_policy.py test
python3 tools/ci_policy.py check-lock
python3 tools/ci_policy.py check-workflows
python3 tools/ci_matrix.py test
python3 tools/ci_matrix.py validate
python3 tools/ci_matrix.py osv-scan
python3 tools/ci_matrix.py gitleaks-scan

composer install --working-dir=php --no-interaction --prefer-dist
composer install --working-dir=conformance --no-interaction --prefer-dist
(cd dart/agentools && dart pub get)
(cd dart/agentools_mcp_dart && dart pub get)
npm ci --ignore-scripts --prefix conformance/mcp

composer validate --strict --working-dir=php
composer test --working-dir=php
composer analyse --working-dir=php
(cd dart/agentools && dart analyze --fatal-infos && dart test)
(cd dart/agentools_mcp_dart && dart analyze --fatal-infos && dart test)

python3 tools/semantic_manifest.py --check
php conformance/bin/semantic-parity
python3 tools/mcp_applicability.py --check
conformance/mcp/bin/run
python3 tools/ci_gate.py --all
python3 tools/adversarial_corpus.py run
python3 tools/mutation_gate.py run
python3 tools/resource_gate.py run

python3 tools/release_candidate.py run
python3 tools/sbom_gate.py run
python3 tools/private_rc_gate.py run
```

## Definition of done

- All required focused checks and the full acceptance suite are green.
- Reference, PHP, and Dart candidate reports have the same exact case set and
  zero failures; semantic parity covers every declared family.
- Generated output was produced only by its owner and the frozen predecessor has
  no diff.
- The final diff contains no skips, broad secret allowlists, baseline weakening,
  public release actions, tags, registry uploads, or unreviewed dependency drift.
- Repository state remains private. A private RC receipt is evidence only and
  never authorizes publication.

Do not commit, push, open a PR, create a tag, publish a package, or modify GitHub
settings, secrets, or environments unless the owner explicitly authorizes that
separate operation.
