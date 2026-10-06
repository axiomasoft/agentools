# Changelog

All notable changes to the private AgenTools distribution are documented here.
Versions follow SemVer for package manifests; spec changes use dated revisions.

## Unreleased (private RC)

### Added

- Hardening contracts: confirmation security, token replay, idempotency lease,
  event store, package boundary, CI/supply-chain, release acceptance matrix
- PostgreSQL adapters for durable token consume, lease fencing, and event append CAS
- Candidate spec revision `2026-09` with semantic manifest and conformance gates
- Private RC subjects (PHP SDK, Dart core, Dart MCP companion) with reproducibility receipt

### Changed

- Supported development target spec: `2026-09` (frozen `2026-08` retained for regression)
- Reference slice and production facades wired through `ConfirmationService` and
  `IdempotencyCoordinator`
- **Breaking:** the PHP PostgreSQL adapters (`PdoPostgresEventStore`,
  `PdoPostgresIdempotencyStore`, `PdoPostgresConfirmationTokenStore`) no longer switch the
  caller's shared `PDO` to `ERRMODE_EXCEPTION`; they reject a connection in any other mode
  with `InvalidArgumentException` (exception mode is the PDO default since PHP 8.0)

### Security

- Structured refusal paths for confirmation binding, TTL/skew, and replay
- Durable spent-token consume and lease fence tokens before external effects
- **Breaking:** `CredentialGuard` (PHP/Dart) is fail-closed: the resource audience and the
  trusted issuer set are required constructor arguments with no built-in defaults; credentials
  sourced from arguments, model output or tool results and confirmation tokens presented as
  access tokens are rejected; reasons and `security_rule` follow the `security-*` vectors;
  argument inspection is recursive with a fail-closed depth limit (SEC-1, SEC-2, SEC-5, SEC-6)
- Signing secrets are marked `#[\SensitiveParameter]` and redacted from debug dumps
  (`SigningKey`, `HmacTokenSigner`); **breaking:** `HmacTokenSigner` (PHP/Dart) requires a
  secret of at least 32 bytes, the same HS256 floor as `SigningKey`

### Fixed

- **Breaking:** `ScenarioRunner::run()` / `ScenarioRunner.run()` take the caller's run
  `IdempotencyKey`; step and compensation keys are derived from it and satisfy the IDE-4
  grammar. The previous `<scenario_id>:<step_id>` keys were rejected by
  `IdempotencyCoordinator` and were identical across runs of the same scenario (IDE-1, IDE-4,
  SCN-8, SCN-25)
- `IdempotencyCoordinator` (PHP/Dart) records a `ToolExecutionException` thrown by the tool
  as the stored failure for the key (IDE-12, IDE-13, IDE-23) instead of leaving the key in
  flight until lease expiry; other throwables still keep the lease for capability-driven
  takeover (HRD-17, HRD-18)
- Postgres/SQL event stores (PHP/Dart) store, chain and hydrate `ts` in one canonical UTC
  form (microsecond precision, fraction omitted when zero); previously `prev_hash` written
  inside a batch did not match the hash of the reloaded event when the input used `Z`,
  fractional seconds or another offset, or the session time zone was not UTC, and hydrated
  `ts` violated the `lifecycle-event.json` UTC requirement

### Documentation

- Root security/support policies, installation, migrations, and operations guides
- Executable documentation gate (`tools/docs_gate.py`)

## 0.0.1

Initial private packages `agentools/sdk`, `agentools`, `agentools_mcp_dart`.
