---
id: D8
date: 2026-09-19
status: accepted
item: P3
items: [P3, P3.1, P3.2, P3.3, P3.4]
supersedes: []
superseded_by: null
---
# D8 — Custom at1 envelope, presentation-consume, injected PostgreSQL adapter

**Actor:** plan-designer/cursor-grok-4.6/high
**Evidence:** RAG:— repository recon in `research/P3-design.md`; RAG:✅ and Perplexity/web cross-check in `research/P3-design-rag.md`

## Решение

P3 использует компактный собственный envelope `at1.` + canonical JSON + HMAC-SHA256, а не JWT
как требование. Подпись покрывает `v`, `alg`, `kid`, `iss`, `aud`, `jti`, `tenant_id`,
`purpose` и существующие CNF-15 поля. `TokenKeyProvider` разделяет ключи по tenant и purpose
со состояниями `active` / `verify_only` / `compromised` / `retired`.

Одноразовость — свойство предъявления (CNF-20): после integrity/time/P2-binding атомарный
`INSERT ... ON CONFLICT DO NOTHING RETURNING` пишет `(tenant_id, jti)` с `outcome=presented`.
Пустой RETURNING есть spent. Отказ authorization не оживляет token. Pre-presentation ошибки
store не пишут.

Production adapter принимает внедрённый PDO (PHP) или SQL executor (Dart); новых Composer/pub
runtime-библиотек нет; `ext-pdo_pgsql` — suggest. In-memory store только testkit. Versioned SQL
поставляется артефактом пакета; PLAN1 не выполняет live migration чужой базы, owner-gate на
P3 items не требуется. Frozen `spec/revisions/2026-08/**` не изменяется. Старый двухсегментный
token без `at1.` после cutover отклоняется.

## Почему

Process-local set не переживает worker/restart (F02). JWT не выбран F06 и добавил бы unsigned
`alg` header, который RFC 8725 как раз описывает как вектор. Success-only consume противоречит
CNF-20. Event store P5 не является unique consume arbiter. `DO UPDATE` скрыл бы победителя.

## Consequences

P3 должен доказать parser mutants, key isolation, один победитель из двух concurrent INSERT,
restart deny и cleanup без replay window. P3 не обещает exactly-once tool effect, KMS и новую
spec revision. P6 вносит коды/поля в shared registry; P7 решает final exports; P8 углубляет
fuzz; P9 включает PostgreSQL в CI matrix, если её ещё нет.
