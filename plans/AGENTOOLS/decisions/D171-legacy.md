---
id: D171
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Gate B round 3 admission нашёл один residual blocker до публикации verdict: CLR-8 observation называл несуществующий в текущем Tasks profile `tasks/result`, а raw task stimulus вообще не называл request type; P4 адресно реопенится P4.21.** Round 3 остаётся незавершённым, новый verdict literal не издан. **Consequences:** pair меняет permitted `GetTaskPayloadRequest` на forbidden control `ListResourcesRequest` при одинаковом `input_required`; semantic invariant связывает raw request type с boundary observation и отвергает fifth-operation mutant.

## Почему

P5.1 adversarial read CLR-8 + P1 final delta п.12/13 против `clarificationstate-002/102`; owner directive №4. `RAG:— (repo-grounded)`
