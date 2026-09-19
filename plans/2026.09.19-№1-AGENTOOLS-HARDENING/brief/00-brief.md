# Brief — PLAN1 Agentools hardening

## Owner request

Проверить `audits/2026-09-19-audit.md` и создать новый основной executable master-plan
`PLAN1` для security, durability, API/package hardening и production-grade release Agentools.
Существующий план `AGENTOOLS` не переоткрывать. Фазы оставить скелетами: каждая будет
детализироваться отдельной сессией наиболее подходящей моделью из `gpt-5.6-sol` и Grok 4.6.
Несущие внешние утверждения подтвердить внешними источниками; использовать широкий поиск и,
если доступен, Perplexity.

## Интерпретация границ

- Результат этой сессии — только верхний executable design: plan, phase skeletons, routing,
  audit adjudication, external foundations, decisions, roadmap и runnable handoff.
- Реализация кода и item-level спецификации не входят в эту сессию.
- `AGENTOOLS` остаётся неизменяемым историческим источником evidence; его P11 не исполняется
  и не переносится в статус нового плана.
- Production-grade означает доказанный local/private release candidate. Public registry upload,
  tag, смена license/legal identity требуют отдельного owner gate `G-RELEASE`.

## Success criteria этой сессии

1. Каждая существенная находка аудита имеет verdict и ровно одну owning phase.
2. P1–P10 существуют как protocol-valid skeletons, без преждевременных implementation items.
3. Для каждой фазы выбран Sol или Grok 4.6 и объяснён выбор.
4. Зафиксирован порядок: detail all phases → independent design audit → execute.
5. Handoff содержит один runnable Next на отдельную детализацию P1.
6. Generated views и `plan-lint --gate all` проходят.

## Retrieval boundary

Perplexity connector в текущем окружении не предоставлен. Вместо фиктивного использования
проведён широкий web-поиск, а несущие тезисы сверены с первичными official sources. Ограничение
явно перенесено в план: будущие phase-design сессии повторяют Perplexity retrieval при наличии,
но подтверждают выводы первоисточниками.
