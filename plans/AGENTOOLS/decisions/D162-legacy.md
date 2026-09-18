---
id: D162
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Observational evidence принадлежит harness, а не SUT event payload.** Разрешены пять закрытых классов: tool/credential boundary spy, journal projection, audit sink, task binding; artifact checker вычисляет verdict из raw candidate/reference. Поля `projection_source`, `write_back_upcast`, `source_of_truth`, `hash_input_includes_prev_hash`, `chain_valid`, SUT-payload `execution_route`, `reject_passing_entries`, `grease_allowed` и magic carrier/type strings запрещены exact gate'ом. **Consequences:** часть fixtures становится подробнее и P6 runner обязан реализовать harness-owned probes; взамен мутант не проходит, просто напечатав правильный payload.

## Почему

Gate B F9–F15; `research/22-p5-round2-observational-remediation-2026-08.md` §2. `RAG:— (repo-grounded)`
