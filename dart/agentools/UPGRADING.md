# Dart SDK upgrading notes

Pre-1.0 candidate surface. In-memory and fixed doubles moved off the core
barrel to `package:agentools/agentools_testkit.dart`.

| Previous (`package:agentools/agentools.dart`) | Current |
|:--|:--|
| `FixedClock` | `package:agentools/agentools_testkit.dart` |
| `InMemoryEventLog` | `package:agentools/agentools_testkit.dart` |
| `InMemoryEventStore` | `package:agentools/agentools_testkit.dart` |
| `InMemorySessionStore` | `package:agentools/agentools_testkit.dart` |
| `InMemoryDeduplicationStore` | `package:agentools/agentools_testkit.dart` |
| `InMemoryConfirmationTokenStore` | `package:agentools/agentools_testkit.dart` |
| `StaticTokenKeyProvider` | `package:agentools/agentools_testkit.dart` |

Add:

```dart
import 'package:agentools/agentools_testkit.dart';
```

Core production code should keep `import 'package:agentools/agentools.dart';`
and inject `Sql*` adapters or your own port implementations.

`lib/src` imports remain unsupported.

## Spec 2026-09

Regenerate locks and run `dart test` after fixture bumps. Pair with PHP SDK via
conformance reports, not shared runtime state.
