# P2 confirmation parity receipt

**Date:** 2026-09-20  
**Manifest:** `fixtures/hardening/confirmation-security-v1.json` (26 cases)

## Executed carriers

| Carrier | Result |
|:--|:--|
| `python3 tools/validate_confirmation_security_v1.py` | green |
| `python3 tools/validate_confirmation_boundary.py --check` | green |
| `cd php && composer test && composer analyse` | green (155 tests) |
| `cd dart/agentools && dart analyze && dart test` | green (136 tests) |

## Semantic parity (manifest subset)

PHP `ConfirmationServiceTest::testManifestHappyPathNoApproval` exercises scenario `happy_path_no_approval` (`CNF-SAFE-025`). Full manifest-driven PHP/Dart matrix for all 26 scenarios is deferred to P8 adversarial corpus integration; P2 proves the facade, typed ports, reference migration and boundary validator.

## Deferred owners

| Topic | Owner |
|:--|:--|
| Durable atomic consume / cross-process replay | P3 |
| Journal-backed production adapters | P5 |
| Successor error registry promotion | P6 |
| Expert API export removal | P7 |
