---
type: analysis
tags: [tariffler, audit, ecosystem, planning-input]
created: 2026-10-30
status: planning-input
source_file: ../../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md
source_lines: "341–363, 462–474, 2905–2988, 3333–3395, 7259–7276, 7935–7965"
---

# Tariffler: entitlement и admission AI-сценариев

Вход для будущего плана Opus. Выводы диалога сведены без повторов; это не план исполнения и не подтверждение реализации. Общие поправки и зависимости: [Edufy-Ecosystem-Audit-Map-2026-10-30](../../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md).

## Извлечённая потребность

AI runtime должен до provider call знать subject/tenant/purpose, разрешённую capability, limits/reservation и текущий entitlement. Методика, размер context и платный профиль могут менять ресурсную квоту; базовая безопасность ребёнка от тарифа не зависит.

Это применение существующей роли Tariffler, не запрос сделать его model router или финансовым ledger. ControLLM эмитит UsageEvent, Tariffler считает entitlement/quota; Billium получает денежные usage/overage-факты по собственному контракту без прямого импорта Tariffler.

## Что предусмотреть в будущем плане

- Явный BudgetPort/host adapter AgenTools для допуска и резервирования; убрать двойное ведение бизнес-квот в `agt_budget_*` и Tariffler.
- Изоляцию user/company/tenant, subject и purpose/agent_code; pin профиля не пиннит разрешение навсегда.
- Anti-abuse request count и ресурсные units отдельно; cached/uncached tokens, audio, partial/unknown usage и корректировки не маскировать нулём.
- Финальный usage появляется после stream; модельные estimates/admission и settlement по фактическому usage — разные события.
- Provider fallback/model retry не повторяет бизнес-списание; unknown финансового outcome не освобождает резерв автоматически. Кто держит money reservation, решает актуальный Commerce contract.
- При недоступности owner нельзя локальным fallback выдать billable permission. Технический emergency disable deployment применяется отдельно от entitlement.

## Ограничение scope

Диалог не содержит нового детального проекта Tariffler. Требуется согласовать имеющийся entitlement-контракт с AgenTools/Commerce, сохранив offline/Dart требования существующих consumers. Школьный Billing остаётся текущим исполнителем до отдельной доказанной миграции; готовность Tariffler этой раскладкой не заявляется.
