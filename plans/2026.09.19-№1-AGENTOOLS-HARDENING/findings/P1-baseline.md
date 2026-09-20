# P1 pre-refactor baseline

- Recorded commit: `8ce43f47735d827be3872bcb8c01d09dd38ed22d`
- Captured at (UTC): 2026-09-20T04:38:15Z
- Manifest digest: `41aa813c817db931b3f8b24ce80a8e0e8b379c5da14f45c70a062d07a76a7ed9`
- Inventory digest: `f990c2214284c6f32b50446da33b42660e12ed2ef9ff9d8799b482fb5a24f3e9`

## Probe matrix

| probe_id | disposition | exit | duration_ms |
|:--|:--|:--:|--:|
| `ci.layer1` | green | 0 | 5549 |
| `ci.layer2` | green | 0 | 187 |
| `ci.layer3` | red | 1 | 104 |
| `ci.layer4` | red | 1 | 138 |
| `dart.core.analyze` | red | 1 | 693 |
| `dart.core.pub_get` | green | 0 | 1132 |
| `dart.core.publish_dry_run` | red | 65 | 1378 |
| `dart.core.test` | green | 0 | 3791 |
| `dart.mcp.analyze` | green | 0 | 259 |
| `dart.mcp.pub_get` | green | 0 | 794 |
| `dart.mcp.publish_dry_run` | red | 65 | 1683 |
| `dart.mcp.test` | green | 0 | 3486 |
| `php.analyse` | green | 0 | 2761 |
| `php.archive` | green | 0 | 4358 |
| `php.composer.install` | green | 0 | 55010 |
| `php.composer.validate` | red | 2 | 89 |
| `php.test` | green | 0 | 324 |

## Inventory

- PHP reachable classes (candidate surface, not stable API): **133**
- Fixture JSON files: **344**

### Dependency lock SHA-256

- `conformance/composer.lock`: `3fca1daa7b7b6469c5316cf56c62dacb34e09f3c7ca03c8912fb68165ab36440`
- `dart/agentools/pubspec.lock`: `bcf37bb4b10622d0315be9d733cb02ece62aa6b2d44773b5e71a8cf3bd33f382`
- `dart/agentools_mcp_dart/pubspec.lock`: `efc7853cac34edbc7e4f54db6d7bbb0d54da51ab69b8876d4dd6f81f6af3b689`
- `php/composer.lock`: `8cca16d97219d02a061bee5d671b4429a4b386560013285f7a212cad0e0944c1`

### Dart barrel exports

**dart/agentools** (12 export lines)
- agentools.dart:src/confirmation/confirmation.dart
- agentools.dart:src/contracts/contracts.dart
- agentools.dart:src/effects/effects.dart
- agentools.dart:src/errors/errors.dart
- agentools.dart:src/idempotency/idempotency.dart
- agentools.dart:src/identity/identity.dart
- agentools.dart:src/journal/journal.dart
- agentools.dart:src/ops/ops.dart
- agentools.dart:src/persistence/persistence.dart
- agentools.dart:src/scenario/scenario.dart
- agentools.dart:src/smoke.dart
- agentools.dart:src/tool/tool.dart
**dart/agentools_mcp_dart** (1 export lines)
- agentools_mcp_dart.dart:src/mcp_dart_transport.dart

## Environmental limits

- Disposable git worktree at recorded commit.
- Probe timeouts may mark long jobs unavailable.

