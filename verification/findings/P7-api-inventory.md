# P7 API inventory — current reachability to target roster

**Schema:** `package-surface/v1`  
**PHP entries:** 199  
**Dart entries:** 187  
**Source tree digest:** `b4ef0fddee452d08f03afda4d0897a9d38f1200633186dce095ab20fa4eb65ca`

## Tier counts

| Tier | Count |
|:--|--:|
| `stable` | 12 |
| `advanced` | 315 |
| `testkit` | 14 |
| `reference` | 44 |
| `internal` | 1 |

## P1 baseline digest delta

P1 captured path-derived PHP names and one-level Dart barrel exports. P7.1 inventories
tokenized PHP types and transitive Dart export closures. The sets below are explained,
not ignored.

- PHP identities only in P1 path-map: **7**
- PHP identities new since P1: **73**
- Dart one-level barrel rows only in P1: **0**
- Dart one-level barrel rows new since P1: **8**

- P1 baseline derived PHP identities from relative paths, including script files without types.
- P7.1 tokenizes class/interface/trait/enum declarations and fails closed on collisions.
- PHP/Dart additions after the P1 capture are P2–P6 hardening types, not P7 semantic changes.

### PSR-4 path/namespace case mismatches

- `php:AgenTools\Reference\Conformance\SqliteJournalExercise`

### PHP path-map identities without tokenized types

- `AgenTools\Idempotency\InMemoryDeduplicationStore`
- `AgenTools\Persistence\FixedClock`
- `AgenTools\Persistence\InMemoryEventLog`
- `AgenTools\Persistence\InMemorySessionStore`
- `AgenTools\Reference\conformance\SqliteJournalExercise`
- `AgenTools\Reference\conformance\adapter`
- `AgenTools\Reference\conformance\product_slice_adapter`

## Pending ownership moves

These current identities remain reachable until P7.2/P7.3 rewrite autoload/exports.
**Pending:** 0  
**Missing target:** 0  
**Undeclared extra:** 0


## Cross-tier rules

- Production PHP autoload must not map `AgenTools\Reference\` or `AgenTools\TestKit\`.
- `agentools.dart` must not export testkit implementations after P7.3.
- Core must not depend on `mcp_dart` or Flutter.
- `lib/src` remains technically importable; it is unsupported API.

