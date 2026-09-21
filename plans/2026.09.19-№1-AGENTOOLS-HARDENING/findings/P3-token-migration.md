# P3 token / TokenSigner migration inventory

**Status:** inventory for P3.1 cutover. Runtime migration is P3.2–P3.4; final export removal is P7.

Old two-segment `payload.sig` tokens without `at1.` / `v` / `kid` are denied after cutover.
No dual-read parser.

| Consumer/reachability | Before | P3 disposition | Deferred |
|:--|:--|:--|:--|
| `php/src/Confirmation/ConfirmationToken.php` | unordered JSON MAC, two segments, no `jti`/`kid` | P3.2 `at1` codec; closed claim set | P6 JCS oracle |
| `dart/agentools/lib/src/confirmation/confirmation_token.dart` | same two-segment layout | P3.2 matched codec | P6 JCS oracle |
| `php/src/Confirmation/HmacTokenSigner.php` | one-secret `TokenSigner` | deprecated one-key adapter; `TokenKeyProvider` is the production port | P7 removal |
| `dart/agentools/lib/src/confirmation/hmac_token_signer.dart` | one-secret `TokenSigner` | deprecated one-key adapter | P7 removal |
| `php/src/Contracts/TokenSigner.php` | production signing port | deprecated test/compat port | P7 surface |
| `dart/agentools/lib/src/contracts/token_signer.dart` | production signing port | deprecated test/compat port | P7 surface |
| `php/src/Confirmation/ConfirmationProtocol.php` | `$spentTokens` keyed by raw token | P3.4 durable consume; delete production local set | P7 expert API |
| `dart/agentools/lib/src/confirmation/confirmation_protocol.dart` | `_spentTokens` keyed by raw token | P3.4 durable consume; delete production local set | P7 expert API |
| `php/src/Confirmation/ConfirmationService.php` | process-local spend via protocol | P3.4 requires `TokenKeyProvider` + `ConfirmationTokenStore` | — |
| `dart/agentools/lib/src/confirmation/confirmation_service.dart` | process-local spend via protocol | P3.4 requires key provider + store | — |
| `php/reference/Crypto/TenantHmacTokenSigner.php` | tenant HMAC behind `TokenSigner` | P3.4 migrate to tenant/purpose `TokenKeyProvider` | P7 packaging |
| `php/reference/Confirmation/SliceConfirmationDependencies.php` | constructs protocol with `TokenSigner` | P3.4 wire key provider and store | P7 packaging |
| `php/reference/Scenario/ReferenceConfirmationServiceFactory.php` | `new TenantHmacTokenSigner(...)` | P3.4 factory uses key provider | P7 packaging |
| `php/tests/Confirmation/ConfirmationProtocolTest.php` | `HmacTokenSigner('test-secret')` | P3.4 focused tests may wrap one-key provider | P7 expert API |
| `php/tests/Reference/Crypto/TenantHmacTokenSignerTest.php` | tenant isolation of old signer | P3.4 superseded by key-isolation tests | P7 |
| `dart/agentools/test/confirmation/confirmation_protocol_test.dart` | `HmacTokenSigner('test-secret')` | P3.4 focused tests may wrap one-key provider | P7 expert API |
| `php/src/Error/ToolErrorCode.php` | spent/invalid/expired only | P3.2+ add frozen P3 spellings | P6 shared registry |
| `dart/agentools/lib/src/confirmation/confirmation_error_codes.dart` | spent/invalid/expired only | P3.2+ add frozen P3 spellings | P6 shared registry |
| `php/src/Ops/CredentialGuard.php` | name-kind guard only | unchanged; not a signer | — |
| `dart/agentools/lib/src/ops/credential_guard.dart` | name-kind guard only | unchanged; not a signer | — |
| PHP PSR-4 `TokenSigner` / protocol reachability | candidate surface | retained deprecated | P7 surface |
| Dart `agentools.dart` barrel | exports token/signer/protocol | compatibility retained until P7 | P7 barrel |

Repository-wide production/reference search found no other `TokenSigner` implementations.
Test fakes must remain in test files. In-memory consume stores are testkit/reference only.
