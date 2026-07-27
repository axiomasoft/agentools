# PIN — MCP `2026-07-28`, статус: **RC** (Release Candidate)

Пин по решению владельца **D130** (`plan.md` §5, план `AGENTOOLS`): владелец снял требование
«только финал» и явно разрешил пиннить release-candidate снимок, если на дату исполнения item'а
P1.1 финал ещё не опубликован. **Это пин RC, не финала — решение владельца D130.**

## Факты пина

| Поле | Значение |
|:--|:--|
| Репозиторий | `modelcontextprotocol/modelcontextprotocol` |
| Тег | `2026-07-28-RC` (GitHub Release: «MCP 2026-07-28 RC», `prerelease: true`) |
| Коммит-SHA | `9d700ed62dcf86cb77475c9b81930611a9182f46` |
| Дата коммита | 2026-05-29T12:49:07Z |
| Путь схемы в репозитории на эту дату | `schema/draft/schema.json` (директория `schema/2026-07-28/` в репозитории **ещё не заведена** — раскладка отличается от ожидаемой: рабочая копия ревизии на RC-стадии живёт в `schema/draft/`, промоция в датированную директорию — штатный шаг публикации финала, ещё не произошедший) |
| `sha256` вендоренного `schema.json` | `bd19a7a9e6ee3a7394aec816cd0660a0156864e828a348129baccdb83457d234` |
| Дата сверки (эта сессия) | 2026-07-27 |

## Статус ревизии на дату сверки

**RC (Release Candidate), не финал.** Подтверждено ДВУМЯ независимыми источниками на дату
сверки 2026-07-27:

1. GitHub Releases (https://github.com/modelcontextprotocol/modelcontextprotocol/releases) —
   релиз `2026-07-28-RC` несёт флаг `prerelease: true`; текст релиза: «This release marks the
   release candidate (RC) `2026-07-28` revision. […] this specification is not final. Changes
   may be introduced between the RC and the final release.» Стабильный (`prerelease: false`)
   релиз с тегом `2026-07-28` в списке релизов репозитория отсутствует — последний стабильный
   тег на дату сверки — `2025-11-25`.
2. Официальный блог MCP, «The 2026-07-28 MCP Specification Release Candidate»
   (https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/): «The release
   candidate is locked as of May 21, 2026. The final specification will be published on
   July 28, 2026.»

## Команда воспроизведения

```bash
curl -s "https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/9d700ed62dcf86cb77475c9b81930611a9182f46/schema/draft/schema.json" -o schema.json
sha256sum schema.json
# ожидаемо: bd19a7a9e6ee3a7394aec816cd0660a0156864e828a348129baccdb83457d234  schema.json
```

Проверка тега на коммит:

```bash
curl -s "https://api.github.com/repos/modelcontextprotocol/modelcontextprotocol/git/refs/tags/2026-07-28-RC"
# object.sha == 9d700ed62dcf86cb77475c9b81930611a9182f46
```

## Отложенный чекпоинт

`DR-01` §A3 addendum «Ре-верификация 2026-07-28» — **остаётся отложенным, не закрыт этим
item'ом**: заявленная дата публикации финала (2026-07-28) на дату этой сверки (2026-07-27) ещё
не наступила. Байты `schema.json` сохранены как скачаны (без reformat/`jq`-прогона) —
`sha256sum` воспроизводится один-в-один.
