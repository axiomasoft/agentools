---
id: D65
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P5.2 поднят `implementation/medium`/`plan-exec` → `implementation/high`/`manual`.** Черновая строка скелета была ниже фактического риск-класса: freeze — необратимая публикация контракта, который начинают пинить фазы P6–P10 и оба контура; ошибка порядка шагов (тег раньше проверки свежести) замораживает расхождение молча. Тир НЕ поднят до frontier: открытых решений в item'е нет — механика предписана D19/D62, а единственное суждение вынесено владельцу гейтом Q-5/Q-10 (Tier policy: «prescribed implementation against already-pinned canons → implementation/high»; effort high+ обязателен для инвариантов, но тир не поднимает)

## Почему

`plan-protocol` §9 Tier policy + деривация `Exec` (effort > medium ⇒ `manual`); прецедент строк P3.14 и P4.5 — та же формула «детерминированная работа, effort high за инвариантность». `RAG:— (repo-grounded: plan.md §3 строки P3.14 и P4.5 — та же формула «детерминированная работа, effort high за инвариантность»; phases/P5.md P5.2 Scope — механика шести шагов предписана D19/D62)`
