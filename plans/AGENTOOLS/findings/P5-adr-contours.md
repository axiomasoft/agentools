---
type: finding
plan: AGENTOOLS
item: P5.1
date: 2026-08-03
---

# P5.1 — живая сверка ADR контуров

Сверка выполнена 2026-08-03 по живым канонам, а не по выжимке `20_research/R-00_internal-recon.md`.
Якорь vault-репозитория: `b0cdf6a1e599e4e90260f56078f56561375b3376`; `git status --short -- <перечень
прочитанных канонов>` пуст, поэтому строки ниже относятся к байтам этого якоря. База `R-00` от
2026-07-07 не разошлась с текущими канонами по проверяемым контрактам.

| Контур и живой канон | Правила ревизии | Вердикт | Основание и граница адаптера |
|:--|:--|:--|:--|
| Axioma — `ADR-004 AI via MCP Only.md` | EXT-1…EXT-17, TLP-1…TLP-17, SEC-1…SEC-6 | совместимо | Единственный AI-канал — типизированные MCP tools, прямого доступа к БД нет. AgenTools добавляет профиль и события поверх этого канала, не создавая обходной транспорт. |
| Axioma — `ADR-005 Finance Immutability.md` | OVR-18…OVR-23, TLP-10…TLP-16, CNF-1…CNF-41 | совместимо | Запрет прямого финансового posting строже общего профиля AgenTools. Адаптер каталога обязан исключать posting; `draft` не повышается до `write`/`irreversible`. |
| Axioma — `ADR-010 AI Write Tiers.md` | OVR-18…OVR-23, TLP-10…TLP-17, CNF-3…CNF-36, AOB-14…AOB-24 | нужен адаптер | `Tier 0/1/2` остаётся политикой контура и отображается без ослабления в `side_effect`; он не становится вторым полем провода. Инициатор + AI в audit совместимы с тройкой принципалов. |
| Axioma — `ADR-012 AI Interaction and Provider Strategy.md`, `modules/axioma_mcp.md`, `modules/axioma_assistant.md` | EXT-13…EXT-17, CLR-1…CLR-21, LOP-1…LOP-27, SEC-7…SEC-19 | нужен адаптер | Единый text/voice pipeline, сменные LLM/ASR и вызов через `axioma_mcp` совместимы. `IntentRouter` должен отдавать формальные provenance/principal/task inputs AgenTools; сам текст запроса таким носителем не является. |
| Flex/CoreX — `ADR-020 AiGateway.md` | AOB-1…AOB-31, SEC-1…SEC-19 | нужен адаптер | Обязательный AiGateway с PII-маской, budget/ledger и запретом прямых provider calls совместим. AgenTools не заменяет gateway: экспорт событий и correlation выполняются адаптером. |
| Flex — `B-13_crm.md` §3.8, §7.3 и `B-16_satellite-contracts.md` | TLP-9…TLP-17, CNF-3…CNF-36, SEC-13…SEC-19 | нужен адаптер | Единый ToolRegistry, intersection прав user×agent, удаление недоступных tools из каталога и повторная проверка на execute совместимы. `stake low/med/high` и существующий HITL отображаются в `side_effect`/policy; три гейта не должны стать тремя носителями решения. |

## Вывод

Контрактных конфликтов с принятыми ADR не найдено. Все строки «нужен адаптер» уже имеют адрес в
P9/P10: они запрещают прямое копирование терминов `tier`/`stake` в провод AgenTools и требуют
сохранить единственные точки авторизации, каталога и provider access каждого контура. Эта
совместимость не исправляет красные находки самого корпуса P5.1: адаптер нельзя проверять
вектором, стартовое состояние которого не выражает provenance, principal, baggage или исход tool.
