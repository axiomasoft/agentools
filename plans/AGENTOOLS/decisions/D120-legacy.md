---
id: D120
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Расхождение §3 Routing фаз P3 и P4 с директивой брифа владельца зафиксировано явно, а не молча.** Бриф 2026-07-27 (`brief/01-refinements.md` §Routing) предписал `A2 = opus/high` и `A3 = sonnet/medium`; фактический §3 после детализации: P3.1 и P3.14 — `implementation/high`, P3.15 — `implementation/low`/`plan-exec`, ВСЕ восемь items P4 — `implementation/high`. Обоснование каждого отклонения уже стоит построчно в колонке «Почему» (Tier policy §9: предписанная реализация против замороженного канона ⇒ implementation; effort high за инвариантность), но САМО отклонение от прямой директивы владельца нигде не называлось — это дефект трассировки, а не маршрута. Отклонения: (1) ВНИЗ по тиру на P3.1/P3.14/P3.15 — items не пишут нормативную прозу (каркас, генератор индекса, бухгалтерия); (2) ВВЕРХ по effort на всех items P4 (`medium` → `high`) — авторинг данных контракта против замороженных правил, где ложная ссылка на правило проходит машинный гейт и обесценивает покрытие. Тир на P4 НЕ поднят. Альтернатива «привести §3 к букве брифа» отклонена: `sonnet/medium` на P4 дал бы `Exec = plan-exec` на items, где различающая сила вектора — предмет суждения; альтернатива «промолчать» отклонена — предписанный Routing'ом overshoot канон прямо называет дефектом плана наравне с undershoot исполнителя

## Почему

`plan-protocol` §9 Tier policy («Tier overshoot PRESCRIBED by Routing is as much a plan defect as executor undershoot») + §7 DoR (бриф синхронизирован); `RAG:— (repo-grounded: brief/01-refinements.md §Routing (§3 плана) — таблица пяти волн; plan.md §3 строки P3.1, P3.14, P3.15, P4.1–P4.8)`
