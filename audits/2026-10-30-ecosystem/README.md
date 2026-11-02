# AgenTools: аудиты развития экосистемы

Подготовлено 2026-10-30. Это очищенный материал для будущего плана Opus, не реализация и не подтверждение готовности. Исходные предложения, факты из локальных документов и принятые уточнения владельца разделены.

**Цель: единая платформа AgenTools; отдельного Botkit не будет.** Сохранить внутри Assistant Runtime и Actions. Отдельный EvalKit не включён в схему; необходимые проверки качества проектируются в AgenTools и Edufy.

Начать с 01, затем 03 и 02/05/06. 04 — полный технический справочник, который нужно сверить с реальным кодом; дерево не является готовым планом создания всех классов.

| Файл | Содержание |
|:--|:--|
| [AgenTools-01-Unified-Platform-2026-10-30](AgenTools-01-Unified-Platform-2026-10-30.md) | AgenTools: единая платформа вместо AgenTools и Botkit |
| [AgenTools-02-Context-Profiles-Knowledge-2026-10-30](AgenTools-02-Context-Profiles-Knowledge-2026-10-30.md) | AgenTools: профили, контекст, память и Knowledge |
| [AgenTools-03-Runtime-Recovery-2026-10-30](AgenTools-03-Runtime-Recovery-2026-10-30.md) | AgenTools: исполнение, эффекты, подтверждения и recovery |
| [AgenTools-04-Technical-Reference-2026-10-30](AgenTools-04-Technical-Reference-2026-10-30.md) | AgenTools: полный технический справочник RFC |
| [AgenTools-05-Models-Cache-Operations-2026-10-30](AgenTools-05-Models-Cache-Operations-2026-10-30.md) | AgenTools: модели, кэш, безопасность и эксплуатация |
| [AgenTools-06-Evaluation-Options-2026-10-30](AgenTools-06-Evaluation-Options-2026-10-30.md) | AgenTools: оценка качества; отдельный EvalKit не принят |
| [ControLLM-AI-Usage-2026-10-30](related/ControLLM-AI-Usage-2026-10-30.md) | ControLLM: единый usage-контракт AI-платформы |
| [Tariffler-AI-Admission-2026-10-30](related/Tariffler-AI-Admission-2026-10-30.md) | Tariffler: entitlement и admission AI-сценариев |

Общая карта: [владение, зависимости и поправки](../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md). Источник: [неизменный диалог Perplexity](../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md).

Соседний проект Edufy: [аудиты Edufy](../../../../edufy/audits/2026-10-30-ecosystem/README.md).

Межрепозиторные ссылки рассчитаны на текущую локальную раскладку `projects/edufy` и `projects/packages/*`; они не являются ссылками на опубликованные GitHub-файлы. Планы и задачи — в workspace Swissknifeman.
