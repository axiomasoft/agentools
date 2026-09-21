# agentools (Dart core)

Dart implementation of the AgenTools candidate spec **2026-09**.

## Usage

```bash
dart pub get
dart analyze --fatal-infos
dart test
```

Import `package:agentools/agentools.dart` for supported APIs. Test doubles live in
`package:agentools/agentools_testkit.dart` and must not ship in production defaults.

## Documentation

- [API.md](API.md)
- [UPGRADING.md](UPGRADING.md)

Private distribution (`publish_to: none`). Pair with PHP SDK via conformance fixtures,
not via shared runtime coupling.
