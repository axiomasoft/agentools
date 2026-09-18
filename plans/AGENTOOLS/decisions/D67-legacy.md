---
id: D67
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Раскладка фазы и ТРИ единственных носителя факта:** последовательность и состав четырёх слоёв CI живут ТОЛЬКО в `tools/ci_gate.py` (workflow `.github/workflows/ci.yml` вызывает его и своей логики не несёт); множество существующих SDK и способы их запуска — ТОЛЬКО в `conformance/sdks.json`; КАКУЮ ревизию реализует SDK — ТОЛЬКО в манифесте самого SDK (`DR-01` §J8), поле ревизии в реестре ЗАПРЕЩЕНО. Плюс гигиена: `conformance/composer.lock` коммитится через вложенный `conformance/.gitignore` с `!composer.lock` (механика D34), проверка `git check-ignore -v` обязательна. Альтернатива «шаги прямо в `ci.yml`» отклонена: локальный прогон и CI разъезжаются молча, а гейт обязан воспроизводиться локально 1:1

## Почему

второй носитель одного факта — источник дрейфа (принцип D41/D62); сверка, читающая обе стороны из одного файла, сверяет файл сам с собой. `RAG:— (repo-grounded: findings/P6-conformance-recon-2026-07-27.md §2 — вывод `git check-ignore -v`: `/conformance/composer.lock` игнорируется корневым `.gitignore`; bakeoff/.gitignore — проверенное переисключение)`
