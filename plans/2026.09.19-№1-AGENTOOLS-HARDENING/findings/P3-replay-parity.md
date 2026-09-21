# P3 replay parity receipt

## Supported path wiring

- PHP and Dart `ConfirmationService` require `TokenKeyProvider`, `ConfirmationTokenStore`, `issuer`, and `audience`.
- Prepare/commit use `at1` via `TokenEnvelope`; legacy two-segment tokens are rejected at parse.
- Atomic `consume` runs after P2 binding checks and before policy/approval/authorization; outcomes refine to `authorized`, `authorization_denied`, or `policy_denied`.
- Production `spentTokens` / `_spentTokens` removed from `ConfirmationProtocol`.

## Evidence

| Case | PHP | Dart |
|------|-----|------|
| `happy_path_consume` | `ConfirmationTokenStoreTest` | `confirmation_token_store_test.dart` |
| `empty_returning_spent` | `ConfirmationTokenStoreTest` | `confirmation_token_store_test.dart` |
| `digest_jti_collision` | `ConfirmationTokenStoreTest` | `confirmation_token_store_test.dart` |
| `restart_after_consume` | `PdoPostgresConfirmationTokenStoreTest` | PostgreSQL-only (PHP mandatory) |
| `authorization_denied_then_spent` | `DurableReplayGateTest` | `durable_replay_gate_test.dart` |
| shared store replay gate | `DurableReplayGateTest` | `durable_replay_gate_test.dart` |

## Residual owners

- **P5/P6**: packaging and public docs for store constructors.
- **P7**: reference contour may use `InMemoryConfirmationTokenStore` only in test/reference namespaces.
- **P8/P9**: CI matrix for `ext-pdo_pgsql` and ephemeral PostgreSQL jobs.
