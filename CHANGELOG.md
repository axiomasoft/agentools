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

### Security

- Structured refusal paths for confirmation binding, TTL/skew, and replay
- Durable spent-token consume and lease fence tokens before external effects

### Documentation

- Root security/support policies, installation, migrations, and operations guides
- Executable documentation gate (`tools/docs_gate.py`)

## 0.0.1

Initial private packages `agentools/sdk`, `agentools`, `agentools_mcp_dart`.
