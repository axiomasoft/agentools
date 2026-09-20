# P8 design RAG — mutation, PostgreSQL schedules and benchmark evidence

**Verified:** 2026-09-19  
**Retrieval:** Perplexity web+academic synthesis was used to expand the candidate/failure matrix;
every load-bearing external premise below was rechecked in a primary source. Perplexity reported an
older `mutation_test` release, while the primary pub.dev page showed `1.8.1` published six days
before verification; the plan uses the primary value and treats synthesis as navigation, not proof.

| Evidence | Verified claim | P8 consequence |
|:--|:--|:--|
| RAG:✅ [Infection introduction](https://infection.github.io/guide/), [usage/config](https://infection.github.io/guide/usage.html), accessed 2026-09-19 | Infection is AST-based PHP mutation testing; its config supports source scope, logs, `minMsi`, `minCoveredMsi`, coverage reuse and `timeoutsAsEscaped`. It requires a green original suite. | P8.3 uses scoped Infection for PHP, calibrates rather than imports thresholds, treats timeouts as escaped and preserves raw survivor evidence. |
| RAG:✅ [`mutation_test` 1.8.1](https://pub.dev/packages/mutation_test), accessed 2026-09-19 | The active Dart-capable tool applies configurable literal/regex text replacements and runs external test commands; pub.dev labels its uploader unverified. | It is evaluated but not used as an Infection-equivalent percentage gate. P8 owns a smaller digest-bound semantic mutant catalog for Dart. |
| RAG:✅ [`hegel` 0.3.0](https://pub.dev/packages/hegel), accessed 2026-09-19 | The young Dart PBT package advertises generators, shrinking, stateful/concurrent tests and reproduction blobs; the page says example persistence is disabled under CI. | Useful candidate, not the normative cross-language owner. P8 requires portable minimized cases and receipts independent of package-local persistence. |
| RAG:✅ [Eris repository](https://github.com/giorgiosironi/eris), accessed 2026-09-19 | Eris targets the PHP/PHPUnit ecosystem, but the repository did not establish PHPUnit 12 compatibility in the checked top-level material. | P8 does not make Eris a prerequisite; one language-neutral generator/shrinker avoids an unverified PHP/Dart compatibility pair. |
| RAG:✅ [RFC 8785](https://www.rfc-editor.org/info/rfc8785/), accessed 2026-09-19 | JCS requires I-JSON inputs, rejects duplicate names/invalid Unicode/non-finite values, preserves Unicode without normalization, sorts by UTF-16 code units and emits UTF-8 canonical bytes. | P8.2 generates raw-invalid and typed domains separately, uses byte-level/metamorphic properties and reuses P6’s independent oracle. |
| RAG:✅ [PostgreSQL `INSERT`](https://www.postgresql.org/docs/current/sql-insert.html), [transaction isolation](https://www.postgresql.org/docs/current/transaction-iso.html), accessed 2026-09-19 | `ON CONFLICT DO UPDATE` gives atomic insert-or-update under concurrency; at Read Committed a conflict may involve a row not conventionally visible to the command snapshot. | P8.4 asserts operation outcome and final durable state, not a SELECT-then-write visibility story. Commit and rollback conflict schedules are distinct. |
| RAG:✅ [PostgreSQL date/time functions](https://www.postgresql.org/docs/current/functions-datetime.html), accessed 2026-09-19 | `now()`/`transaction_timestamp()` are fixed at transaction start; `statement_timestamp()` is statement start; `clock_timestamp()` advances during statement execution. | Lease schedules obey the P4 clock contract, record the actual time source and avoid sub-millisecond sleep assertions. |
| RAG:✅ [PostgreSQL `pg_stat_activity`](https://www.postgresql.org/docs/current/monitoring-stats.html), [`pg_blocking_pids`](https://www.postgresql.org/docs/current/functions-info.html), accessed 2026-09-19 | PostgreSQL exposes backend PID/application name/state/wait event and the exact PIDs blocking a backend. | Explicit barriers plus server observation prove blocked edges; process count or elapsed sleeps do not. |
| RAG:✅ [PostgreSQL serialization failure handling](https://www.postgresql.org/docs/current/mvcc-serialization-failure-handling.html), accessed 2026-09-19 | Serialization retry must rerun the complete transaction and decision logic, and retry may still fail repeatedly. | Any retry scenario records attempts and re-enters the whole transaction; a single-statement retry is a red mutant when the upstream contract uses serializable retry. |
| RAG:✅ [`benchmark_harness` 2.4.0](https://pub.dev/packages/benchmark_harness), accessed 2026-09-19 | The official Dart tools package supplies sync/async benchmark harnesses, but cautions that results are only carefully comparable for the same benchmark, machine and OS; default `BenchmarkBase` timing has legacy multi-call semantics. | P8.5 pins it as dev tooling, defines one-call exercise explicitly and refuses timing verdicts across fingerprints. |

## Perplexity contribution and corrections

The synthesis usefully connected three failure classes that the skeleton listed separately:

1. replayable randomized evidence needs generator/corpus/runtime identity in addition to a seed;
2. PostgreSQL blocking should be observed through backend state and blocker identity, not inferred
   from sleeps;
3. correctness/resource ceilings should be authoritative while wall-clock benchmarks remain
   fingerprint-bound.

Primary-source verification changed the tooling conclusion: `mutation_test` is active at 1.8.1,
but its own documentation still describes regex/text replacement and an unverified uploader. The
reason not to publish a Dart MSI equivalent is therefore semantic comparability and supply-chain
qualification, not the stale claim that the package is abandoned.

## Facts intentionally not promoted

- Infection MSI is not a security guarantee; critical named mutants and independent oracles remain
  mandatory even with a high aggregate score.
- No checked primary source establishes a mature Dart AST-mutation standard equivalent to Infection.
  P8 makes the narrower auditable claim of killing its declared semantic fault catalog.
- Property/fuzz case count alone is not completeness. Boundary partitions, metamorphic relations,
  oracle independence and promoted failures carry the evidence.
- Client kill/backend termination tests do not certify database power-loss/WAL durability.
- PostgreSQL timeout settings bound a failed test; they do not prove application latency.
- A same-machine benchmark can still be noisy. Raw samples and robust bands are retained, and a
  mismatched fingerprint cannot produce a pass/fail timing verdict.
