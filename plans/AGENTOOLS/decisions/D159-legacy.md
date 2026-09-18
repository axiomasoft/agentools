---
id: D159
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.13 кодирует behavior stimulus как один structured counterfactual на rule-pair: закрытый enum `value/presence/length`, общий pointer и два отсортированных member-наблюдения; explicit pointer обязан совпасть с вычисленным, а один-sided presence и list-length адресуются без требования leaf существовать в обеих половинах. Mutation evidence считается только при реальном reject validator'ом, не по факту изменения JSON.** Отклонены: self-asserted fixture metadata, `exists-in-either` без relation и подгонка boundary fixtures.

## Почему

`research/20-p4.13-pair-evidence-resume-2026-08.md` §§1–3; текущий P4.13 candidate. `RAG:— (repo-grounded)`
