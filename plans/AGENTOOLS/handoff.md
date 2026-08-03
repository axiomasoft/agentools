# HANDOFF — 2026-08-03 — after P5.1

**Next:** design-phase: task:plan-design AGENTOOLS P4

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — межфазовая remediation красного гейта |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать реопен P4/P3 по F1–F8, не меняя DR-01/DR-02. |
| Команда | `$ task:plan-design AGENTOOLS P4` |

```text
$ task:plan-design AGENTOOLS P4

РЕЖИМ: remediation закрытых P3/P4 по находкам раунда 1 Gate B, НЕ freeze и НЕ реализация P6
ВХОД: plans/AGENTOOLS/artifacts/P5-gate-b.md и findings/P5-adr-contours.md; прочитать F1–F8 и полную матрицу ПЕРВЫМИ
СКОУП: 1) P4.1 — формальный driver state для provenance/principal/auth/baggage/journal/effect/tool-result/task-channel 2) P4.5/P4.7/P4.8 — различающие замены 32 носителей и исправление шести static checker branches 3) P3.11/P3.12 — только если SEC-3 code невыразим 4) P3+owner — фактическая миграция 32/35 domain/vendor carriers и измеримый verdict Q-27 5) повторный cold-start P5.1 после зелёных гейтов
НЕ ТРОГАТЬ: DR-01/DR-02; 2026-07-draft; ADR Flex/Axioma; P6–P11; freeze/tag/contracts P5.2 до GREEN
```

**Done:**

- Gate B round 1 опубликован item-коммитом `3f4a96b`: один `RED`, шесть линз, 382 обязательных маркера, 8 находок.
- Живые каноны Axioma/Flex сверены: конфликтов нет, адаптерные границы вынесены в `findings/P5-adr-contours.md`.
- Формальные A0/A1/A2/A3 перепрогнаны; их PASS отделён от содержательного RED.

**Remaining:**

- Remediation F1–F8 в фазах-владельцах; затем раунд 2 P5.1 новым cold-start заходом.
- P5.3 и P5.2 не стартуют до GREEN P5.1; P6–P11 остаются заморожены.

**Sources of truth:** `artifacts/P5-gate-b.md` · `findings/P5-adr-contours.md` · `phases/P5.md` P5.1 · `research/04-p5-audit-gate-2026-08.md`.

**Open risks:**

- Формальные 382/382 скрывают 116 обязательных правил без годного различающего носителя.
- Все 14 remediation-кейсов P4.5 семантически красные; исправление только coverage-ссылок проблему не закрывает.
- D46 занижает цену namespace: 32 файла domain и 35 файлов domain+vendor.

**Workarounds/Deferred/Open questions:** workaround — нет; deferred — Q-16 до P6.5; open_questions — Q-5, Q-27 и owner-part F7/F8.
