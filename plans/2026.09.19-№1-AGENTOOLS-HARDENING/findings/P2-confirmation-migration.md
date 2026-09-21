# P2 confirmation migration inventory

**Status:** closed-green after P2 gates. Final exports/removal remain P7-owned.

| Consumer/reachability | Before | P2 disposition | Deferred |
|:--|:--|:--|:--|
| `php/reference/Scenario/SliceToolCoordinator.php` | direct protocol prepare/commit with caller-selected security values | migrated to `ConfirmationService`; trusted adapters wired once | P3/P5 |
| `php/reference/Cli/SliceToolCoordinatorFactory.php` | constructed protocol/authorizer directly | constructs safe service and reference-only trusted dependencies | P7 packaging |
| `php/reference/Scenario/DayEndOrchestrator.php` | constructed protocol/authorizer directly | constructs safe service through trusted dependencies | P7 packaging |
| `php/tests/Confirmation/ConfirmationProtocolTest.php` | focused legacy tests | retained on validator allowlist | P7 expert API |
| `dart/agentools/test/confirmation/confirmation_protocol_test.dart` | focused legacy tests | retained on validator allowlist | P7 expert API |
| `php/src/Confirmation/ConfirmationService.php` | absent | only supported PHP production legacy call site | P3 consume |
| `dart/agentools/lib/src/confirmation/confirmation_service.dart` | absent | only supported Dart production legacy call site | P3 consume |
| PHP PSR-4 protocol/authorizer reachability | candidate surface | retained and deprecated | P7 surface |
| Dart `agentools.dart` barrel | exports protocol/authorizer | compatibility retained; safe facade added | P7 barrel |

Repository-wide production/reference search found no other direct consumers. The boundary validator
owns the executable allowlist and reports compatibility exports separately.
