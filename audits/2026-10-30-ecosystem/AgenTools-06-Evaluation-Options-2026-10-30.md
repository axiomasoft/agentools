---
type: analysis
tags: [agentools, evaluation, audit, planning-input]
created: 2026-10-30
status: planning-input
source_file: ../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md
source_lines: "758–796, 1464–1497, 2685–2700, 3592–3778, 7086–7113, 7336–7358, 8419–8467"
---

# AgenTools: проверка качества и допуск моделей

Вход для будущего плана Opus. Выводы диалога сведены без повторов; это не план исполнения и не подтверждение реализации. Общие поправки и зависимости: [Edufy-Ecosystem-Audit-Map-2026-10-30](../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md).

## Решение владельца и граница

**Отдельный EvalKit сейчас под сомнением и в целевую схему не включён.** Проверки качества нужны в AgenTools и Edufy; новый обязательный пакет, репозиторий или внешний eval-сервис не предлагается. Это уточнение владельца имеет приоритет над предложением диалога автоматически переиспользовать EvalKit.

AgenTools отвечает за технический harness, regression и deployment/profile qualification; Edufy поставляет педагогические gold/scorers и предметную экспертизу. Если существующий EvalKit когда-либо даст доказанное повторное использование, его можно рассмотреть отдельно. Сейчас это открытая возможность, не зависимость и не отдельный план.

Дерево `extensions/evaluation` и `agt_eval_*` в техническом справочнике — сохранённое предложение исходного RFC; физическое выделение пакета не принято. Начать с необходимых проверок внутри AgenTools.

## Наборы Edufy

| Suite | Что доказывает |
|:--|:--|
| Tutoring/pedagogy | Русский школьный запрос, физика/математика/формулы, условия законов/теорем, методика, подсказки и проверка понимания |
| Knowledge | Полнота extraction, формулы/единицы/страницы, условия/исключения, сохранённый evidence, correction/invalidation |
| Retrieval/privacy | Нужный evidence без лишних chunks, разрешённые источники, tenant/audience isolation, teacher-only answer keys не раскрыты |
| Agent/tools | Верный инструмент, отказ запрещённому, scope/confirmation/input binding, missing data без фантазий, timeout/unknown/recovery/cost limits |
| Child safety/handoff | Возрастные ограничения, запрещённые действия, корректная передача человеку и provenance |
| Voice | Детская русская речь, noise, формулы/произношение, barge-in, delivered semantics, text↔voice continuity |
| Reports | Шаблон/обязательные поля/числа, provenance, missing data, draft acceptance/правки, confirmation официального результата |
| Cross-provider | Одна цель и профиль на разных deployments, long session, tokenizer/cache/usage differences, безопасный disable/fallback |

## Допуск и rollout

Qualification привязана к **deployment + profile/task + версиям suite/prompt/skills/tool schema/material corpus**, не к бренду модели вообще. Доступный provider может быть не допущен к physics tutor или child safety. Технический health не заменяет quality qualification.

Каждая новая модель/prompt/profile/skill/tool schema проходит требуемый regression до rollout; canary, emergency block и rollback не меняют активные сессии скрытно. Вторая модель-judge — вспомогательная проверка, не доказательство верности формулы или доменного факта.

## Метрики и evidence

Knowledge correctness/grounding/полнота условий; педагогическая уместность; retrieval precision/overhead; goal completion и time to useful result; cost per completed goal; cache read/write/storage окупаемость; STT errors/voice latency/interruption; lost/duplicate turns и unknown effects; privacy violations и redaction failures.

Пороги после измеренного baseline и экспертной оценки, а не произвольные проценты. Сохранять versions/hashes/source refs, deterministic scorer outputs, ограничения и budget. Отделять unit/fixture/isolated PostgreSQL/host E2E/live provider/pilot evidence; fake-only PASS не доказывает readiness.

## Вход Opus

Определить в AgenTools минимальный формат case/suite/run/result/qualification и использовать подходящие существующие проверки. На первом срезе — небольшой golden journey + fault matrix; затем предметные suites, provider opt-in tests на тестовых данных, shadow A/B и production canary. Gold ученика/школы не переносить в общий публичный набор без отдельной политики доступа.
