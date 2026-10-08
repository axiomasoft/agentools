# P1 migration and consumer-notification inventory

**Evidence date:** 2026-09-20  
**Policy owner:** `root/contracts/compatibility-support-v1.md`

Each row links an audit finding or contract surface to an expected migration class and owning phase.
No migration is executed in P1.

| ID | Source | Compatibility risk | Migration class | Source → target shape | Evidence needed | Consumer | Notification trigger | Owner |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| M-001 | F01 / TM-001 | Authorization bypass | API + behaviour | Caller-injected context → trusted seams | P2 negative tests | Application integrators | P2 item closure | P2 |
| M-002 | F02 / TM-002 | Cross-process replay | Data + token store | In-memory spent map → durable atomic consume | P3 replay proofs | All confirmation users | P3 GREEN | P3 |
| M-003 | F03 / TM-003 | Policy gap | API | Permissive authorizer → bound proposal context | P2 integration tests | Application integrators | P2 GREEN | P2 |
| M-004 | F04 / TM-004 | Duplicate/missed effects | Data + API | resolve/complete only → lease/fence lifecycle | P4 crash schedules | Workers with side effects | P4 GREEN | P4 |
| M-005 | F05 / TM-005 | Lost/colliding events | Schema + API | single append → batch/version/collision contract | P5 storage proofs | Event consumers | P5 GREEN | P5 |
| M-006 | F06 / TM-006 | Token confusion | Wire format | Implicit fields → versioned envelope | P3 vectors | All token verifiers | P3 GREEN | P3 |
| M-007 | F07 / TM-007 | Cross-language drift | Schema/fixtures | Ad-hoc errors → registry + JCS oracle | P6 parity gate | PHP/Dart SDK users | P6 GREEN | P6 |
| M-008 | F08 / TM-008 | False compliance narrative | Docs/process | Internal reports → scoped MCP receipts | P6 matrix | Operators | P6 GREEN | P6 |
| M-009 | F09 / TM-009 | Accidental API lock-in | Package surface | Wide reachability → P7 manifest | P7 export proofs | Package consumers | P7 GREEN | P7 |
| M-010 | F10 / TM-010 | Unproven guarantees | Test corpus | Unit-only → adversarial gates | P8 evidence bundle | Maintainers | P8 GREEN | P8 |
| M-011 | F11 / TM-011 | Supply-chain weakness | CI/release | Partial workflows → attested pipeline | P9 RC artifacts | Release operators | P9 GREEN | P9 |
| M-012 | F12 / TM-012 | Untested shipping | Release process | Source-only → archive consumers + dossier | P10 acceptance packet | Owner / consumers | P10 GREEN | P10 |
| M-013 | F13 / TM-013 | Observability | Bookkeeping | Epoch timestamps → corrected projections | P5 projection/audit proofs | Operators | P5 parity gate | P5 |
| M-014 | PHP `composer validate` RED | Metadata drift | Package metadata | Current composer.json → valid strict manifest | Baseline or P9 | PHP consumers | Next baseline diff | P9 |
| M-015 | `ci_gate` layers 3–4 RED | Conformance gap | Test harness | Failing layers → green lockstep | CI receipt | SDK maintainers | CI green on main | P6/P9 |
| M-016 | Dart core analyze RED | Static analysis debt | Code quality | Analyzer infos/errors → clean analyze | `dart analyze` green | Dart consumers | Baseline improvement | P7/P8 |
| M-017 | `publish_to: none` dry-run RED | Publication policy | Identity | Private packages → owner-authorized public identity | D5 decision + metadata | External registry users | Owner gate | P10 |

## Consumer map (summary)

| Consumer type | Primary migrations | Notification channel |
|:--|:--|:--|
| Application embedding confirmation/idempotency | M-001–M-005 | Release notes + spec revision bumps |
| MCP transport integrators | M-006, M-008, companion package splits | MCP pin + companion changelog |
| Package dependents (PHP/Dart) | M-009, M-014–M-016 | SemVer + P7 manifest |
| Release/CI operators | M-011–M-012, M-017 | P9/P10 dossier and owner packet |
