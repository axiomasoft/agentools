# External foundations — 2026-09-19

## Метод и граница

Проведён широкий web retrieval; Perplexity connector в текущей сессии отсутствовал. Каждый
несущий тезис ниже проверен по первичному official source, а не по поисковому summary. Дата
проверки — 2026-09-19. Эти источники задают ограничения и критерии; конкретные Agentools API,
schemas и версии выбираются только в соответствующей phase-design сессии.

| Evidence | Подтверждённый тезис | Следствие для PLAN1 |
|:--|:--|:--|
| RAG:✅ [MCP official conformance](https://github.com/modelcontextprotocol/conformance) | Upstream conformance привязан к поддерживаемым spec revisions и отдельным client/server scenarios. | P6 хранит official applicability/report отдельно от Agentools-only conformance. |
| RAG:✅ [MCP specification 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28) | Versioned protocol contract требует явной negotiation/compatibility позиции. | P1/P6 фиксируют supported revision matrix, не используют слово «MCP compliant» без scope. |
| RAG:✅ [GitHub secure use reference](https://docs.github.com/en/actions/reference/security/secure-use) | GitHub рекомендует минимальные permissions и pin third-party actions на full-length commit SHA; OIDC предпочтительнее long-lived cloud credentials. | P9 проектирует least-privilege, immutable pins и secretless publication where applicable. |
| RAG:✅ [GitHub artifact attestations](https://docs.github.com/en/actions/concepts/security/artifact-attestations) | Attestations дают cryptographically signed provenance claims и policy verification, но не доказывают runtime correctness. | P9 выпускает/проверяет attestations; P10 не подменяет ими behavior tests. |
| RAG:✅ [SLSA build provenance v1.2](https://slsa.dev/spec/v1.2/build-provenance) и [build track](https://slsa.dev/spec/v1.2/build-track-basics) | Provenance описывает builder, inputs и build process; уровни требуют возрастающей защищённости build platform. | P9 выбирает достижимый SLSA-aligned target и не заявляет уровень без evidence. |
| RAG:✅ [Dart package publishing](https://dart.dev/tools/pub/publishing) | `dart pub publish --dry-run` валидирует package, а опубликованные версии неизменяемы; archive contents должны быть просмотрены до publish. | P7/P9/P10 проверяют локальный archive и dry-run; реальный publish защищён G-RELEASE. |
| RAG:✅ [Dart pub downgrade](https://dart.dev/tools/pub/cmd/pub-downgrade) | Downgrade резолвит lowest allowed dependency versions для compatibility testing. | P9 имеет отдельный lowest-dependencies lane, не только latest lock. |
| RAG:✅ [Composer schema: archive](https://getcomposer.org/doc/04-schema.md#archive) | `archive.exclude` управляет содержимым distributable archive. | P7 задаёт allow/deny contract, P9 инспектирует фактический artifact. |
| RAG:✅ [PostgreSQL INSERT](https://www.postgresql.org/docs/18/sql-insert.html) | `ON CONFLICT` предоставляет atomic insert-or-update outcome при корректном unique arbiter. | P3/P5 могут опираться на DB atomicity, но exact schema/transaction/isolation доказываются adapter tests. |
| RAG:✅ [AWS Powertools idempotency](https://docs.aws.amazon.com/powertools/python/develop/utilities/idempotency/) | Production idempotency различает in-progress/completed records, expiry и payload validation; concurrent first requests требуют locking strategy. | P4 обязан проектировать lifecycle/lease/fingerprint, а не только result cache. |
| RAG:✅ [RFC 8785 — JCS](https://www.rfc-editor.org/rfc/rfc8785.html) | Canonical JSON задаёт deterministic property ordering/serialization и ограничения I-JSON. | P6 использует external corpus/oracle и byte-level fixtures для PHP/Dart. |
| RAG:✅ [RFC 8725 — JWT BCP](https://www.rfc-editor.org/rfc/rfc8725.html) | Verifier должен pin algorithms и mutually exclusive validation rules; unsafe algorithm agility недопустима. | P3 вводит allowlist/version/purpose separation независимо от выбора JWT или custom envelope. |
| RAG:✅ [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) | Deny-by-default, every-request validation и ABAC/ReBAC context уменьшают authorization gaps. | P2 передаёт trusted actor/resource/action/environment context policy engine и тестирует bypasses. |
| RAG:✅ [PHP supported versions](https://www.php.net/supported-versions.php) | Supported branches и security-support windows меняются со временем. | P1/P9 пинят датированную support matrix и пересматривают её перед release. |
| RAG:✅ [SPDX specification](https://spdx.github.io/spdx-spec/v3.0/) | SPDX — machine-readable standard для software supply-chain/component data. | P9 выбирает и валидирует SBOM format/version вместо произвольного dependency dump. |

## Design implications

1. Нельзя обещать exactly-once external effects из одного idempotency store: PLAN1 обещает
   только атомарное admission/replay и fencing, а end-to-end guarantee зависит от downstream.
2. Canonicalization и cross-language parity требуют независимых vectors; self-generated expected
   values не являются oracle.
3. Supply-chain evidence — отдельный слой от runtime correctness: tests, archive inspection,
   SBOM, provenance и attestation нужны совместно.
4. Public publication необратима для package versions и затрагивает legal identity; поэтому
   production-grade candidate отделён от G-RELEASE.

## Обновление evidence

Каждая phase-design сессия создаёт `research/Pn-design-rag.md`, повторно проверяет только
релевантные меняющиеся premises и фиксирует query/source/date. Perplexity используется для
расширения поля поиска при доступности; в итоговую норму попадает только primary-source proof.
