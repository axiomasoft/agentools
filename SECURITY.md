# Security policy

AgenTools is distributed as a **private, proprietary** codebase. This document
describes how to report security issues for the current private distribution. It
does **not** define a public SLA, bug bounty, or guaranteed response time.

## Supported versions

Security fixes are considered only for the **current private release candidate**
bytes described in the latest `P9-private-rc` aggregate (PHP SDK, Dart core, and
private-paired MCP companion). Older workspace checkouts without a matching
subject digest are unsupported.

| Component | Identity |
|:--|:--|
| PHP SDK | `agentools/sdk` (Composer, proprietary) |
| Dart core | `agentools` (`publish_to: none`) |
| Dart MCP companion | `agentools_mcp_dart` (paired private subject) |

Spec revision **2026-09** is the candidate normative revision; **2026-08** remains
frozen for regression only.

## Reporting

Report suspected vulnerabilities through the repository owner's private security
channel for this distribution. Include:

- affected package and version/commit or RC subject digest
- reproduction steps or proof-of-concept
- impact assessment (confidentiality, integrity, availability)

Do not open public issues for exploit details.

## Handling principles

- Confirmation, token, idempotency, and event-store defects are triaged against
  the hardening guarantee catalog (for example replay, fencing, collision).
- Fixes may require SQL migration or key rotation; see [docs/operations.md](docs/operations.md).
- Claims of MCP upstream certification are out of scope; only documented
  **official MCP scenario compatibility** evidence applies.

## Non-goals

- Public CVE assignment workflow until owner publication policy (D5) is decided
- Hosted attestation guarantees (currently unavailable on this plan tier)
