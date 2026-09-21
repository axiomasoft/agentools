# P6 semantic migration inventory

**Base:** frozen `spec/revisions/2026-08` (tag `spec-2026-08`, bytes unchanged).

**Successor:** candidate `spec/revisions/2026-09` + `fixtures/2026-09/`.

| Area | `2026-08` | `2026-09` delta | Consumer action |
|:--|:--|:--|:--|
| Error codes | 17 core `agentools.*` in schema | +28 hardening codes (IDE-31 table) | Adopt spellings from manifest; no hidden aliases |
| Confirmation | legacy protocol only in spec | `HRD-1..HRD-5`, `ConfirmationService` contract | Wire trusted ports; deny caller injection |
| Token replay | local spent sets in spec narrative | `HRD-9..HRD-12`, durable consume | `ConfirmationTokenStore` required on supported path |
| Idempotency | three-state IDE model | `HRD-15..HRD-18`, lease/fencing + coordinator | Unfenced production `complete` removed |
| Journal | `EventLog` in spec | `HRD-21..HRD-25`, `EventStore` port | Inject store; keyset pagination only |
| Spec negotiation | `specRevision: 2026-08` | `2026-09` self-contained reader boundary | Declare `2026-09` when claiming hardening guarantees |

Residual: public tag `spec-2026-09`, full behavior vector re-execution on successor adapters (P6.5), package exports (P7).
