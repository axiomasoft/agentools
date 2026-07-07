# fixtures/

Языконезависимые тест-векторы — исполняемая часть договора между PHP и Dart SDK.
Формат вектора и golden-файлов описывается здесь по мере готовности спеки
(фаза P0, item P0.9 плана `plans/AGENTOOLS` в vault `academici/brain`).

Ожидаемая структура (заполняется в P0.9):

- `schema/fixture.json` — JSON Schema формата вектора.
- `vectors/**/*.json` — сами векторы (L1/L2/L3 conformance-уровней).
- `golden/jcs/*.{input.json,golden.txt}` — контрольные пары канонизации RFC 8785.
- `golden/idempotency/*.{input.json,golden.txt}` — контрольные пары idempotency-ключа.

До заполнения этот README — placeholder.
