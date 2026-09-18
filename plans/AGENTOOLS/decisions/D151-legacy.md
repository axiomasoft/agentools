---
id: D151
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Schema-values gate двухслойный: обычный/full `spec_lint` остаётся stdlib-only и fail-closed отвергает любой branch/keyword вне канонического shape; специальный `--schema-values-selftest` обязан сверять probes и мутации через `jsonschema.Draft202012Validator`, а отсутствие oracle завершает команду exit 2.** Сетевой install из инструмента запрещён. Отклонено: расширять частичный интерпретатор по одному keyword и снова иметь молчаливую неполноту; отклонено: полный JSON Schema validator в обычном lint. **Consequences:** supersedes D14 только для адресного schema-values selftest; платим явной локальной test-зависимостью, зато отсутствие зависимости или новая форма не могут дать семантический PASS.

## Почему

`findings/P3-jsonschema-oracle-2026-08.md`; `research/14-p3-open-enum-fail-closed-2026-08.md` §§1–2. `RAG:✅ 2026-08-04: официальная документация python-jsonschema, Draft202012Validator`
