# Dart SDK API

Package `agentools` exposes two public libraries. Files under `lib/src` are
implementation and are not a consumer contract.

## Supported entry

`package:agentools/agentools.dart` is the supported core barrel. Consumers
should depend on:

- ports under the contracts export
- `ConfirmationService`, `IdempotencyCoordinator`, `EventStore` and the value
  objects those facades require
- optional SQL adapters (`SqlEventStore`, `SqlIdempotencyStore`,
  `SqlConfirmationTokenStore`) when an executor is injected

Exact identities and tiers live in `api/package-surface-v1.json`. Reachability
is not a stability promise; `stable` requires a production consumer and a
behavioural test.

## Development contour

`package:agentools/agentools_testkit.dart` is the only supported test-double
entrypoint (`FixedClock`, `InMemory*`, `StaticTokenKeyProvider`). Core never
imports it. Production constructors must not default to in-memory stores.

## Not supported

- Importing `package:agentools/src/...`
- Importing testkit types from `package:agentools/agentools.dart`
- Treating every `lib/src` type as SemVer-stable
- pub.dev publication (private/proprietary until G-RELEASE)
