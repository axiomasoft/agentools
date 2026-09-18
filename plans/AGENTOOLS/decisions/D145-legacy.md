---
id: D145
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Q-27 закрыт веткой `measurable`: actionable-description имеет четыре непустых компонента в фиксированном порядке — назначение, условия вызова, обязательные аргументы и пример.** Published/authoring schema и stdlib-linter проверяют только эту структуру; эффективность конкретной малой модели остаётся отдельным измерением P10.6. Альтернативы отклонены: `defer` ослабляет прямое требование брифа; длина/NLP-скоринг выдаёт эвристику за качество модели.

## Почему

Ответ владельца в сессии P3.18: `measurable`; `brief/00-brief.md` §3; `artifacts/P5-gate-b.md` F8; `research/12-p3-p4-gate-b-remediation-2026-08.md` §3. `RAG:— (owner decision + repo-grounded)`
