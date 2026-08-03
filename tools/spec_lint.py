#!/usr/bin/env python3
"""spec_lint.py — проверка целостности ревизии спецификации AgenTools.

Использование:
    python3 tools/spec_lint.py <revision_dir> [--allow-missing] [--coverage --write|--check]
    python3 tools/spec_lint.py --keywords-check <schema.json> [--list A|B]
    python3 tools/spec_lint.py --legacy-check <revision_dir>
    python3 tools/spec_lint.py --fixtures-check <fixtures_root>

Ревизий в репозитории больше одной, и они живут по РАЗНЫМ конвенциям (`2026-07-draft`
— история, `2026-08` — modern-only против MCP 2026-07-28), поэтому линтер параметризован
СЛОВАРЁМ ПРОФИЛЕЙ `REVISION_PROFILES`: профиль выбирается по имени каталога ревизии,
неизвестное имя — внятная ошибка, а не молчаливый дефолт.

Общие проверки (обе ревизии):
  1. Каждый schema/*.json парсится и содержит корректные $schema/$id.
  2. Каждый нормативный NN_*.md существует (пропускается с --allow-missing).
  3. Markdown-ссылки на файлы внутри ревизии разрешаются.
  4. Блоки ```json в доках парсятся как валидный JSON.
  5. Если json-блок начинается с комментарием "// example: <schema-file>" —
     соответствующий файл схемы должен существовать.
  6. fixtures/ — структура тест-векторов и golden-пар.

Дополнительные проверки профиля `2026-08` (все — следствия anti-regression списка
`research/00-user-intent.md` §4, конвенции — `research/02-p3-authoring-conventions-2026-08.md` §5):
  1. Одно перечисление — один $defs (№1).
  2. `description` не вводит норму RFC 2119 (№5).
  3. Каждый $ref резолвится офлайн (№6).
  4. Ключевые слова схем по Спискам A/B (`DR-02` §2–§3).
  5. Грамматика нормативной строки: уникальный маркер, ровно один уровень (№3).
  6. Структурная сверка примера со своей схемой (№2).
  7. Легаси-лексика вне огороженного блока (D20/D45).
  8. Таблица покрытия решений `DR-01` в каждом документе.

Режим `--fixtures-check` (P4.1/P4.5, `research/03-p4-vector-corpus-2026-08.md` §3) — структурная
сверка корпуса `fixtures/2026-08/**` со схемой класса своего вектора (`behavior`/`artifact`/
`harness`), проверка `spec_rev`, потолка `spec_rule_ids` (8, анти-вырождение) и соответствия
каталога классу. Класс `artifact` дополнительно исполняется: фактический приговор над `subject`
сверяется с `expect.linter` и адресной `diagnostic_substring`. Полная JSON-Schema-валидация
`behavior`/`harness` вне периметра (D14, stdlib-only) — приходит эмпирически (`bakeoff/`) и с
раннером фазы P6. Двунаправленная traceability и отчёт покрытия включаются общим проходом
ревизии `2026-08`; `--coverage --write|--check` владеет производным `coverage.md`.

Без сетевых обращений, stdlib-only. Ошибки собираются списком и печатаются
разом; exit 1 при наличии хотя бы одной ошибки, иначе exit 0.
"""
import json
import re
import sys
from pathlib import Path

EXPECTED_SCHEMA_DIALECT = "https://json-schema.org/draft/2020-12/schema"

MD_LINK_RE = re.compile(r"\[[^\]]*\]\(([^)]+)\)")
JSON_BLOCK_RE = re.compile(r"```json\n(.*?)\n```", re.DOTALL)
EXAMPLE_COMMENT_RE = re.compile(r"^//\s*example:\s*(\S+)\s*$")

SESSION_STATES = {
    "CREATED", "RUNNING", "WAITING_INPUT", "WAITING_APPROVAL", "EXECUTING_TOOL",
    "COMPLETED", "FAILED_RETRYABLE", "FAILED_TERMINAL", "CANCELLED", "EXPIRED",
}
FIXTURE_REQUIRED_KEYS = ["id", "title", "spec_refs", "conformance_level", "given", "script", "expect"]
SCRIPT_STEP_KEYS = {"user_input", "llm_stub", "clarification_answer", "approval", "tick_seconds"}

# --- Списки A и B (`DR-02` §2–§3) --------------------------------------------
# Копируются в код ЯВНЫМИ множествами, а не выводятся эвристикой: расхождение
# профиля с решением обязано быть видно диффом этого файла.

# Ключевые слова, запрещённые В ЛЮБОМ списке: они меняют ФОРМУ документа и не
# переживают ни одного разобранного таргета (`DR-02` §2 — p04, p07 + норма §E5/§E6).
KEYWORDS_FORBIDDEN_ALWAYS = ("$dynamicRef", "$dynamicAnchor", "prefixItems")

# Список A — опубликованные схемы: провод ОТКРЫТ, закрытия нет вовсе (`DR-01` §J9).
KEYWORDS_FORBIDDEN_LIST_A = ("unevaluatedProperties",)

LEGACY_TERMS_2026_08 = (
    "initialize",
    "Mcp-Session-Id",
    "Last-Event-ID",
    "roots/",
    "sampling/",
    "logging/",
    "Dynamic Client Registration",
    "DCR",
    "HTTP+SSE",
    "SSE-resumability",
    "server-initiated",
)
LEGACY_FENCE_START = "<!-- legacy-lexicon-allowed:start -->"
LEGACY_FENCE_END = "<!-- legacy-lexicon-allowed:end -->"
LEGACY_FENCE_DOC = "00_overview.md"

RFC2119_WORDS_RE = re.compile(r"(?<![A-Za-z])(MUST NOT|MUST|SHOULD NOT|SHOULD|MAY)(?![A-Za-z])")

COVERAGE_HEADING = "## Покрытие решений"

# Извлечение label решения из строки таблицы покрытия: первая ячейка вида
# «§B7 (пиннинг дескриптора, …)» или «D19 (…)» — label обрывается на первом
# пробеле/скобке/бэктике, описание в скобках не часть идентификатора решения.
COVERAGE_ROW_RE = re.compile(r"^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*$")
COVERAGE_LABEL_RE = re.compile(r"^`?(?P<label>[^\s(`]+)")

DOCS_2026_08 = [
    "00_overview.md",
    "01_extension-identity.md",
    "02_tool-contract-profile.md",
    "03_action-lifecycle.md",
    "04_confirmation.md",
    "05_clarification-mrtr.md",
    "06_scenarios.md",
    "07_long-operations.md",
    "08_audit-observability.md",
    "09_idempotency-errors.md",
    "10_security.md",
    "11_conformance-harness.md",
]

# Маркер ↔ документ (`research/02-…` §2). Маркер чужого документа — ошибка: обе
# ревизии живут в одном репозитории и греп-ищутся одними командами.
RULE_PREFIXES_2026_08 = {
    "00_overview.md": "OVR",
    "01_extension-identity.md": "EXT",
    "02_tool-contract-profile.md": "TLP",
    "03_action-lifecycle.md": "ALC",
    "04_confirmation.md": "CNF",
    "05_clarification-mrtr.md": "CLR",
    "06_scenarios.md": "SCN",
    "07_long-operations.md": "LOP",
    "08_audit-observability.md": "AOB",
    "09_idempotency-errors.md": "IDE",
    "10_security.md": "SEC",
    "11_conformance-harness.md": "CFH",
}

RULE_LINE_RE_2026_08 = re.compile(
    r"^\*\*(?P<id>(?:OVR|EXT|TLP|ALC|CNF|CLR|SCN|LOP|AOB|IDE|SEC|CFH)-T?\d+) "
    r"\((?P<word>MUST NOT|MUST|SHOULD NOT|SHOULD|MAY), (?P<level>L1|L2|L3)"
    r"(?:, Not testable: (?P<reason>[^)]+))?\)\.\*\* (?P<text>.+)$")

# Кандидат в нормативную строку: начинается маркером ревизии. Если кандидат не
# прошёл грамматику — это ошибка, а не «просто текст»: тихого правила без уровня
# не существует (anti-regression №3).
RULE_CANDIDATE_RE_2026_08 = re.compile(
    r"^\*\*(?:OVR|EXT|TLP|ALC|CNF|CLR|SCN|LOP|AOB|IDE|SEC|CFH)-T?\d+\b")

# Матрица «документ → решения DR-01» (`research/02-p3-authoring-conventions-2026-08.md`
# §7 — тот же вход, что читал каждый item P3.2–P3.13 при написании своей таблицы
# покрытия). Данные — КОПИЯ решения research/02-…, не эвристика и не regex по
# прозе: расхождение матрицы с планом обязано быть видно ДИФФОМ этого файла, а не
# пересчётом какой-то формулы по markdown. Item P3.14 сверяет ЕЁ с фактическими
# таблицами `## Покрытие решений DR-01` — сводный гейт покрытия (Scope Included в).
COVERAGE_MATRIX_2026_08 = {
    "00_overview.md": ("§0", "§A3", "§C8", "§E6", "§E7", "D7", "D19"),
    "01_extension-identity.md": ("§A1", "§A2"),
    "02_tool-contract-profile.md": ("§B7", "§I2", "§E5", "§J9"),
    "03_action-lifecycle.md": ("§C1", "§C2", "§C3", "§C4", "§C5", "§C6", "§C7"),
    "04_confirmation.md": ("§B1", "§B2", "§B3", "§B4", "§B5", "§B6", "§B8", "§I4"),
    "05_clarification-mrtr.md": ("§B1",),
    "06_scenarios.md": ("§F1", "§F2", "§F3", "§F4"),
    "07_long-operations.md": ("§G1", "§G2", "§G3", "§G4", "§G5"),
    "08_audit-observability.md": ("§H1", "§H2", "§H3", "§H4", "§H5", "§D4"),
    "09_idempotency-errors.md": ("§D1", "§D2", "§D3", "§E1", "§E2", "§E3", "§E7"),
    "10_security.md": ("§I1", "§I2", "§I3", "§I4"),
    "11_conformance-harness.md": (
        "§J1", "§J2", "§J3", "§J4", "§J5", "§J6", "§J7", "§J8", "§J9", "§E4",
    ),
}

# --- корпус `fixtures/2026-08/**` (`--fixtures-check`, P4.1) -------------------
# Каталог → (класс вектора, файл схемы формата, обязательные поля класса сверх общих
# шести). Три независимых пространства имён id (по каталогу), не одно на весь корпус
# (README.md §8).
FIXTURE_VECTOR_CLASSES = {
    "vectors": {"class": "behavior", "schema": "vector.json", "required": ("given", "script", "expect")},
    "static": {"class": "artifact", "schema": "static-check.json", "required": ("subject", "expect")},
    "harness": {"class": "harness", "schema": "harness-case.json", "required": ("transcript", "expect")},
}
FIXTURE_COMMON_REQUIRED = ("id", "title", "vector_class", "spec_rev", "spec_rule_ids", "conformance_level")
FIXTURE_ID_RE = re.compile(r"^[a-z][a-z0-9]*-[0-9]{3}$")

SCHEMA_MAP_KEYWORDS = ("$defs", "definitions", "properties", "patternProperties", "dependentSchemas")
SCHEMA_ONE_KEYWORDS = (
    "items", "contains", "not", "if", "then", "else", "additionalProperties",
    "unevaluatedProperties", "propertyNames", "contentSchema",
)
SCHEMA_MANY_KEYWORDS = ("allOf", "anyOf", "oneOf", "prefixItems")
MAX_SCHEMA_DEPTH = 16
MAX_SCHEMA_SUBSCHEMAS = 256
LEVEL_ORDER = {"L1": 1, "L2": 2, "L3": 3}

REVISION_PROFILES = {
    "2026-07-draft": {
        # Профиль повторяет поведение линтера до введения профилей: ревизия закрыта
        # (D18) и её проверки не пересматриваются.
        "required_docs": [
            "00_overview.md",
            "01_tool-contract-profile.md",
            "02_action-lifecycle.md",
            "03_confirmation-protocol.md",
            "04_scenario-schema.md",
            "05_audit-events.md",
            "06_idempotency-errors.md",
        ],
        "schema_dirs": [("schema", None)],
        "schema_id_base": "https://agentools.dev/schema/2026-07-draft/",
        "spec_ref_re": re.compile(r"^(TCP|LC|CP|SC|AU|IE)-T?[0-9]+$"),
        "fixtures_root": "fixtures",
        "min_vectors": 15,
        "rule_prefixes": None,
        "rule_line_re": None,
        "keyword_lists": False,
        "legacy_terms": None,
        "coverage_required": False,
        "enum_single_defs": False,
        "description_no_norm": False,
        "refs_offline": False,
        "examples_structural": False,
        "coverage_matrix": False,
    },
    "2026-08": {
        "required_docs": DOCS_2026_08,
        # Список A — опубликованные схемы, Список B — схемы авторинга.
        "schema_dirs": [("schema", "A"), ("schema/authoring", "B")],
        "schema_id_base": "https://agentools.dev/schema/2026-08/",
        "spec_ref_re": re.compile(
            r"^(OVR|EXT|TLP|ALC|CNF|CLR|SCN|LOP|AOB|IDE|SEC|CFH)-T?[0-9]+$"),
        "fixtures_root": "fixtures/2026-08",
        # P4.5: храповик равен фактическому числу файлов трёх классов на item-коммите
        # (behavior + artifact + harness; golden-meta — регистрация покрытия, не вектор).
        "min_vectors": 140,
        # D53 (P4.1): три схемы формата по классу вектора, не единый fixture.json —
        # check_fixtures делегирует структурную сверку --fixtures-check.
        "fixtures_multi_class": True,
        "rule_prefixes": RULE_PREFIXES_2026_08,
        "rule_line_re": RULE_LINE_RE_2026_08,
        "keyword_lists": True,
        "legacy_terms": LEGACY_TERMS_2026_08,
        "coverage_required": True,
        "enum_single_defs": True,
        "description_no_norm": True,
        "refs_offline": True,
        "examples_structural": True,
        "coverage_matrix": True,
    },
}


def resolve_profile(revision_dir):
    """Профиль выбирается по ИМЕНИ каталога ревизии. Неизвестное имя — отказ, а не
    молчаливый дефолт: линтер, применивший чужие конвенции, зелен по ошибке."""
    name = revision_dir.resolve().name
    profile = REVISION_PROFILES.get(name)
    if profile is None:
        known = ", ".join(sorted(REVISION_PROFILES))
        raise KeyError(f"неизвестная ревизия {name!r}: профиля нет (известны: {known})")
    return profile


# --- обход схем ---------------------------------------------------------------

def iter_schema_nodes(node, path="<root>"):
    """Обходит ВСЕ подсхемы документа, отдавая пару (путь, узел). Путь нужен в
    сообщениях: «где-то в схеме» не чинится, а `properties/kind/oneOf/3` чинится."""
    if isinstance(node, dict):
        yield path, node
        for key, value in node.items():
            yield from iter_schema_nodes(value, f"{path}/{key}")
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from iter_schema_nodes(value, f"{path}/{index}")


def load_json(path, errors):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        errors.append(f"{path}: невалидный JSON ({exc})")
        return None


def revision_schema_files(revision_dir, profile):
    """Пары (файл, список A|B). Файлы `schema/authoring/**` не попадают в выборку
    `schema/*.json` — glob не рекурсивен, и это намеренно."""
    for rel, list_name in profile["schema_dirs"]:
        directory = revision_dir / rel
        if not directory.is_dir():
            continue
        for schema_file in sorted(directory.glob("*.json")):
            yield schema_file, list_name


# --- общие проверки -----------------------------------------------------------

def check_required_docs(revision_dir, profile, allow_missing, errors):
    if allow_missing:
        return
    for name in profile["required_docs"]:
        if not (revision_dir / name).is_file():
            errors.append(f"отсутствует обязательный документ: {name}")


def check_schemas(revision_dir, profile, errors):
    base = profile["schema_id_base"]
    for schema_file, list_name in revision_schema_files(revision_dir, profile):
        data = load_json(schema_file, errors)
        if data is None:
            continue
        if not isinstance(data, dict):
            errors.append(f"{schema_file}: корень схемы должен быть объектом")
            continue
        schema_dialect = data.get("$schema")
        if schema_dialect != EXPECTED_SCHEMA_DIALECT:
            errors.append(
                f"{schema_file}: $schema должен быть '{EXPECTED_SCHEMA_DIALECT}', "
                f"получено {schema_dialect!r}"
            )
        prefix = base + ("authoring/" if list_name == "B" else "")
        expected_id = f"{prefix}{schema_file.name}"
        actual_id = data.get("$id")
        if actual_id != expected_id:
            errors.append(
                f"{schema_file}: $id должен быть '{expected_id}', получено {actual_id!r}"
            )
        if profile.get("fixtures_multi_class"):
            try:
                depth, count = schema_metrics(data)
            except TypeError as exc:
                errors.append(f"{schema_file}: {exc}")
            else:
                if depth > MAX_SCHEMA_DEPTH:
                    errors.append(
                        f"{schema_file}: глубина схемы {depth} превышает максимум {MAX_SCHEMA_DEPTH}"
                    )
                if count > MAX_SCHEMA_SUBSCHEMAS:
                    errors.append(
                        f"{schema_file}: число подсхем {count} превышает максимум {MAX_SCHEMA_SUBSCHEMAS}"
                    )


def check_markdown_links(revision_dir, errors):
    revision_root = revision_dir.resolve()
    for md_file in sorted(revision_dir.glob("*.md")):
        text = md_file.read_text(encoding="utf-8")
        for match in MD_LINK_RE.finditer(text):
            target = match.group(1).strip()
            if not target or target.startswith(("http://", "https://", "#", "mailto:")):
                continue
            path_part = target.split("#", 1)[0]
            if not path_part:
                continue
            resolved = (md_file.parent / path_part).resolve()
            try:
                resolved.relative_to(revision_root)
            except ValueError:
                # ссылка выходит за пределы ревизии — не наша забота
                continue
            if not resolved.exists():
                errors.append(f"{md_file}: битая ссылка на {target!r}")


def iter_example_blocks(revision_dir):
    """Отдаёт (md-файл, номер блока, имя схемы|None, текст json). Один разбор
    markdown на две проверки — синтаксис блока и его структурную сверку."""
    for md_file in sorted(revision_dir.glob("*.md")):
        text = md_file.read_text(encoding="utf-8")
        for i, match in enumerate(JSON_BLOCK_RE.finditer(text), start=1):
            block = match.group(1)
            lines = block.split("\n", 1)
            example_match = EXAMPLE_COMMENT_RE.match(lines[0].strip())
            if example_match:
                yield md_file, i, example_match.group(1), (lines[1] if len(lines) > 1 else "")
            else:
                yield md_file, i, None, block


def check_json_blocks(revision_dir, errors):
    schema_dir = revision_dir / "schema"
    for md_file, index, schema_name, json_text in iter_example_blocks(revision_dir):
        if schema_name is not None and not (schema_dir / schema_name).is_file():
            errors.append(
                f"{md_file}: json-блок #{index} ссылается на несуществующую "
                f"схему {schema_name!r}"
            )
        try:
            json.loads(json_text)
        except json.JSONDecodeError as exc:
            errors.append(f"{md_file}: json-блок #{index} невалиден ({exc})")


def _spec_ref_exists(ref, docs_corpus):
    """Маркер (напр. 'LC-T13') MUST существовать в текстах нормативных доков как точное
    вхождение (границы — не буква/цифра/дефис), а не как подстрока внутри другого
    маркера (иначе 'LC-1' ложно совпал бы внутри 'LC-15')."""
    pattern = re.compile(r"(?<![A-Za-z0-9-])" + re.escape(ref) + r"(?![A-Za-z0-9-])")
    return pattern.search(docs_corpus) is not None


def check_fixtures(revision_dir, profile, errors):
    """fixtures — языконезависимые тест-векторы (не часть revision_dir, лежат на
    уровне репозитория). Проверки структурные (без полной JSON-Schema-валидации —
    D14: та приходит с раннером фазы P6); цель — держать векторы и golden-файлы
    честными относительно нормативных доков и формата fixture.json.

    Порог `min_vectors = 0` означает, что корпус ревизии ещё не заведён (его пишет
    фаза P4): отсутствующего каталога в этом случае недостаточно для ошибки."""
    repo_root = revision_dir.resolve().parents[2]
    fixtures_dir = repo_root / profile["fixtures_root"]
    min_vectors = profile["min_vectors"]
    if not fixtures_dir.is_dir():
        if min_vectors == 0:
            return
        errors.append(f"отсутствует директория {profile['fixtures_root']}/ (P0.9)")
        return

    if profile.get("fixtures_multi_class"):
        # D53/P4.5: три класса проверяются одним структурным проходом; artifact-
        # векторы здесь же исполняются. Traceability добавляется после загрузки
        # rules.json в `check_traceability`, чтобы не заводить второй носитель.
        check_fixtures_format(fixtures_dir, profile, errors)
        return

    fixture_schema_path = fixtures_dir / "schema" / "fixture.json"
    if not fixture_schema_path.is_file():
        errors.append(f"отсутствует {profile['fixtures_root']}/schema/fixture.json (P0.9)")
    else:
        load_json(fixture_schema_path, errors)

    docs_corpus = "\n".join(
        (revision_dir / name).read_text(encoding="utf-8")
        for name in profile["required_docs"]
        if (revision_dir / name).is_file()
    )

    vectors_dir = fixtures_dir / "vectors"
    vector_files = sorted(vectors_dir.glob("**/*.json")) if vectors_dir.is_dir() else []
    if len(vector_files) < min_vectors:
        errors.append(
            f"{profile['fixtures_root']}/vectors: найдено {len(vector_files)} векторов, "
            f"требуется минимум {min_vectors} (P0.9)"
        )

    spec_ref_re = profile["spec_ref_re"]
    seen_ids = {}
    for vf in vector_files:
        data = load_json(vf, errors)
        if data is None:
            continue
        if not isinstance(data, dict):
            errors.append(f"{vf}: корень вектора должен быть объектом")
            continue

        for key in FIXTURE_REQUIRED_KEYS:
            if key not in data:
                errors.append(f"{vf}: отсутствует обязательное поле '{key}'")
        if any(key not in data for key in FIXTURE_REQUIRED_KEYS):
            continue

        vector_id = data["id"]
        if vf.stem != vector_id:
            errors.append(f"{vf}: id вектора '{vector_id}' не совпадает с именем файла (Implementation Rules P0.9)")
        if vector_id in seen_ids:
            errors.append(f"{vf}: дублирующийся id '{vector_id}' (уже в {seen_ids[vector_id]})")
        else:
            seen_ids[vector_id] = vf

        spec_refs = data.get("spec_refs")
        if not isinstance(spec_refs, list) or not spec_refs:
            errors.append(f"{vf}: spec_refs должен быть непустым списком (P0.9)")
        else:
            for ref in spec_refs:
                if not isinstance(ref, str) or not spec_ref_re.match(ref):
                    errors.append(f"{vf}: spec_ref '{ref}' не соответствует формату маркера")
                elif not _spec_ref_exists(ref, docs_corpus):
                    errors.append(f"{vf}: spec_ref '{ref}' не найден ни в одном нормативном доке")

        if data.get("conformance_level") not in ("L1", "L2", "L3"):
            errors.append(f"{vf}: conformance_level должен быть L1|L2|L3")

        given = data.get("given")
        if not isinstance(given, dict) or not given.get("tools"):
            errors.append(f"{vf}: given.tools должен быть непустым списком дескрипторов")

        script = data.get("script")
        if not isinstance(script, list) or not script:
            errors.append(f"{vf}: script должен быть непустым списком шагов")
        else:
            for i, step in enumerate(script):
                if not isinstance(step, dict) or len(step) != 1:
                    errors.append(f"{vf}: script[{i}] должен быть объектом ровно с одним ключом")
                    continue
                (step_key,) = step.keys()
                if step_key not in SCRIPT_STEP_KEYS:
                    errors.append(f"{vf}: script[{i}] несёт неизвестный ключ '{step_key}'")

        expect = data.get("expect")
        if not isinstance(expect, dict):
            errors.append(f"{vf}: expect должен быть объектом")
        else:
            if not isinstance(expect.get("events"), list):
                errors.append(f"{vf}: expect.events должен быть списком")
            if expect.get("final_state") not in SESSION_STATES:
                errors.append(f"{vf}: expect.final_state отсутствует или не входит в состояния FSM")

    check_golden_pairs(fixtures_dir / "golden" / "jcs", "vector-*.input.json", errors, min_pairs=3)
    check_golden_pairs(fixtures_dir / "golden" / "idempotency", "key-*.input.json", errors, min_pairs=1)


def check_golden_pairs(golden_dir, input_glob, errors, min_pairs):
    if not golden_dir.is_dir():
        errors.append(f"отсутствует директория {golden_dir} (P0.9)")
        return
    input_files = sorted(golden_dir.glob(input_glob))
    if len(input_files) < min_pairs:
        errors.append(f"{golden_dir}: найдено {len(input_files)} input-файлов, требуется минимум {min_pairs}")
    for input_file in input_files:
        golden_file = input_file.with_name(input_file.name.replace(".input.json", ".golden.txt"))
        if not golden_file.is_file():
            errors.append(f"{input_file}: нет парного golden-файла {golden_file.name}")
            continue
        content = golden_file.read_bytes()
        if not content.endswith(b"\n") or content.endswith(b"\n\n"):
            errors.append(f"{golden_file}: MUST заканчиваться РОВНО одним переводом строки (AU-18)")
        try:
            json.loads(input_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"{input_file}: невалидный JSON ({exc})")


# --- проверки профиля `2026-08` ----------------------------------------------

def _enumeration_values(node):
    """Перечисление узла в нормализованном виде либо None. Две формы: голый `enum`
    и обязательная для расширяемых словарей форма `oneOf` из `const`-веток
    (`DR-01` §E2) — обе описывают ОДНО множество значений и потому сравнимы."""
    if isinstance(node.get("enum"), list) and node["enum"]:
        values = node["enum"]
    elif isinstance(node.get("oneOf"), list) and node["oneOf"]:
        # Ветвь-fallback (`{type: string, pattern: …}`) в подсчёт не входит: она не
        # значение перечисления, а точка расширения (`DR-01` §E2).
        consts = [b["const"] for b in node["oneOf"] if isinstance(b, dict) and "const" in b]
        if len(consts) < 2:
            return None
        values = consts
    else:
        return None
    try:
        return tuple(sorted(json.dumps(v, sort_keys=True) for v in values))
    except TypeError:
        return None


def check_enum_single_defs(revision_dir, profile, errors):
    """anti-regression №1: одно перечисление — один узел `$defs`, подключаемый
    `$ref`. Инлайн-дубль ловится сравнением НОРМАЛИЗОВАННЫХ множеств значений:
    именно так `StepStartedPayload.kind` разъехался со `StepKind` в `2026-07-draft`."""
    inline = {}
    defined = {}
    for schema_file, _ in revision_schema_files(revision_dir, profile):
        data = load_json(schema_file, errors)
        if not isinstance(data, dict):
            continue
        for path, node in iter_schema_nodes(data):
            values = _enumeration_values(node)
            if values is None:
                continue
            target = defined if "/$defs/" in path else inline
            target.setdefault(values, []).append(f"{schema_file}:{path}")

    for values, places in sorted(inline.items()):
        if len(places) > 1:
            errors.append(
                f"перечисление {list(values)} материализовано инлайн {len(places)} раз "
                f"({', '.join(places)}) — обязано жить одним $defs-узлом (anti-regression №1)"
            )
        elif values in defined:
            errors.append(
                f"перечисление {list(values)} объявлено и в $defs ({defined[values][0]}), "
                f"и инлайн ({places[0]}) — инлайн-копия обязана быть $ref (anti-regression №1)"
            )


def check_description_no_norm(revision_dir, profile, errors):
    """anti-regression №5: `description` схемы НЕ вводит норму. Норма живёт только в
    markdown-правиле с маркером — иначе носителей два и они расходятся молча."""
    for schema_file, _ in revision_schema_files(revision_dir, profile):
        data = load_json(schema_file, errors)
        if not isinstance(data, dict):
            continue
        for path, node in iter_schema_nodes(data):
            description = node.get("description")
            if not isinstance(description, str):
                continue
            found = RFC2119_WORDS_RE.findall(description)
            if found:
                errors.append(
                    f"{schema_file}:{path}: description несёт нормативные слова "
                    f"{sorted(set(found))} — норма живёт в markdown-правиле (anti-regression №5)"
                )


def check_refs_offline(revision_dir, profile, errors):
    """anti-regression №6 + `DR-01` §E5: каждый `$ref` резолвится БЕЗ сети —
    внутрифайловый либо относительный на существующий файл ревизии."""
    for schema_file, _ in revision_schema_files(revision_dir, profile):
        data = load_json(schema_file, errors)
        if not isinstance(data, dict):
            continue
        for path, node in iter_schema_nodes(data):
            ref = node.get("$ref")
            if not isinstance(ref, str):
                continue
            if ref.startswith(("http://", "https://")):
                errors.append(
                    f"{schema_file}:{path}: сетевой $ref {ref!r} запрещён "
                    f"(автодереференс — DR-01 §E5, SEP-2106)"
                )
                continue
            if ref.startswith("#"):
                continue
            target = (schema_file.parent / ref.split("#", 1)[0]).resolve()
            if not target.is_file():
                errors.append(f"{schema_file}:{path}: $ref {ref!r} не резолвится офлайн")


def check_keywords(data, list_name, source, errors):
    """Ключевые слова схемы против Списка A (провод) или B (авторинг), `DR-02` §2–§3.

    Разница ровно в закрытии: A запрещает его целиком, B разрешает
    `unevaluatedProperties: false` на внешнем уровне композиции и
    `additionalProperties: false` в листовых подсхемах без `allOf`/`$ref`."""
    for path, node in iter_schema_nodes(data):
        for keyword in KEYWORDS_FORBIDDEN_ALWAYS:
            if keyword in node:
                errors.append(
                    f"{source}:{path}: ключевое слово {keyword!r} запрещено "
                    f"(Список {list_name}, DR-02 §2–§3)"
                )
        ref = node.get("$ref")
        if isinstance(ref, str) and ref.startswith(("http://", "https://")):
            errors.append(f"{source}:{path}: сетевой $ref {ref!r} запрещён (Список {list_name})")

        has_composition = any(k in node for k in ("allOf", "anyOf", "oneOf", "$ref"))
        if list_name == "A":
            for keyword in KEYWORDS_FORBIDDEN_LIST_A:
                if keyword in node:
                    errors.append(
                        f"{source}:{path}: {keyword!r} запрещён в опубликованной схеме — "
                        f"провод открыт (Список A, DR-01 §J9)"
                    )
            if node.get("additionalProperties") is False:
                errors.append(
                    f"{source}:{path}: 'additionalProperties: false' запрещён в опубликованной "
                    f"схеме — ломает must-ignore (Список A, DR-01 §E3/§E5)"
                )
        else:
            if "unevaluatedProperties" in node and not has_composition:
                errors.append(
                    f"{source}:{path}: 'unevaluatedProperties' разрешён только на внешнем "
                    f"уровне композиции (Список B, DR-01 §E5)"
                )
            if node.get("additionalProperties") is False and has_composition:
                errors.append(
                    f"{source}:{path}: 'additionalProperties: false' разрешён только в листовых "
                    f"подсхемах без allOf/$ref (Список B, DR-01 §E5)"
                )


def check_keyword_lists(revision_dir, profile, errors):
    """Список выбирается КАТАЛОГОМ схемы, а не флагом: `schema/` — провод (A),
    `schema/authoring/` — авторинг (B)."""
    for schema_file, list_name in revision_schema_files(revision_dir, profile):
        if list_name is None:
            continue
        data = load_json(schema_file, errors)
        if isinstance(data, dict):
            check_keywords(data, list_name, schema_file, errors)


def check_rule_lines(revision_dir, profile, errors):
    """Грамматика нормативной строки (`research/02-…` §3): маркер + нормативное слово +
    РОВНО один уровень, всё в самой строке. anti-regression №3: уровень отдельной
    таблицей — второй носитель одного факта, из которого и родились `TCP-13` в двух
    уровнях сразу и `AU-20` без уровня."""
    rule_line_re = profile["rule_line_re"]
    prefixes = profile["rule_prefixes"]
    seen = {}
    for name in profile["required_docs"]:
        doc = revision_dir / name
        if not doc.is_file():
            continue
        own_prefix = prefixes[name]
        for lineno, line in enumerate(doc.read_text(encoding="utf-8").split("\n"), start=1):
            if not RULE_CANDIDATE_RE_2026_08.match(line):
                continue
            match = rule_line_re.match(line)
            if not match:
                errors.append(
                    f"{doc}:{lineno}: строка правила не соответствует грамматике "
                    f"'**<ID> (<СЛОВО>, <Ln>[, Not testable: <причина>]).** <текст>'"
                )
                continue
            rule_id = match.group("id")
            prefix = rule_id.split("-", 1)[0]
            if prefix != own_prefix:
                errors.append(
                    f"{doc}:{lineno}: маркер {rule_id} принадлежит документу с префиксом "
                    f"{own_prefix!r} — чужой префикс запрещён (research/02-… §2)"
                )
            if rule_id in seen:
                errors.append(f"{doc}:{lineno}: дублирующийся маркер {rule_id} (уже в {seen[rule_id]})")
            else:
                seen[rule_id] = f"{doc}:{lineno}"
            word = match.group("word")
            if word in ("MUST", "MUST NOT") and not match.group("text").strip():
                errors.append(
                    f"{doc}:{lineno}: правило {rule_id} ({word}) несёт пустой "
                    f"нормативный текст (research/00-user-intent.md §3 п.1)"
                )
            reason = match.group("reason")
            if reason is not None and not reason.strip():
                errors.append(
                    f"{doc}:{lineno}: правило {rule_id} несёт пустую причину "
                    f"'Not testable:' — тихого SKIP не существует (DR-01 §J3)"
                )


def _schema_surface(schema, root):
    """Свойства и `required` схемы, собранные ЧЕРЕЗ композицию первого уровня
    (`allOf` + внутрифайловые `$ref`). Проверка объявлена структурной: полная
    валидация JSON Schema приходит с раннером фазы P6 (D14 — stdlib-only)."""
    properties = set()
    patterns = []
    required = set()
    seen_refs = set()

    def visit(node):
        if not isinstance(node, dict):
            return
        ref = node.get("$ref")
        if isinstance(ref, str) and ref.startswith("#/") and ref not in seen_refs:
            seen_refs.add(ref)
            target = root
            for part in ref[2:].split("/"):
                if isinstance(target, dict):
                    target = target.get(part.replace("~1", "/").replace("~0", "~"))
            visit(target)
        for key in ("properties", "patternProperties"):
            container = node.get(key)
            if isinstance(container, dict):
                if key == "properties":
                    properties.update(container)
                else:
                    patterns.extend(container)
        if isinstance(node.get("required"), list):
            required.update(r for r in node["required"] if isinstance(r, str))
        for branch in node.get("allOf", []) or []:
            visit(branch)

    visit(schema)
    return properties, patterns, required


def check_examples_structural(revision_dir, profile, errors):
    """anti-regression №2 «схема строже прозы»: пример, помеченный
    `// example: <файл схемы>`, сверяется со своей схемой структурно — каждый ключ
    верхнего уровня объявлен, каждое `required`-поле присутствует."""
    schema_dir = revision_dir / "schema"
    for md_file, index, schema_name, json_text in iter_example_blocks(revision_dir):
        if schema_name is None:
            continue
        schema_path = schema_dir / schema_name
        if not schema_path.is_file():
            continue  # отсутствие файла уже названо check_json_blocks
        schema = load_json(schema_path, errors)
        try:
            example = json.loads(json_text)
        except json.JSONDecodeError:
            continue  # невалидный JSON уже назван check_json_blocks
        if not isinstance(schema, dict) or not isinstance(example, dict):
            continue
        properties, patterns, required = _schema_surface(schema, schema)
        for key in sorted(example):
            if key in properties:
                continue
            if any(re.search(pattern, key) for pattern in patterns):
                continue
            errors.append(
                f"{md_file}: json-блок #{index}: ключ {key!r} не объявлен в {schema_name} "
                f"(структурная сверка, anti-regression №2)"
            )
        for key in sorted(required - set(example)):
            errors.append(
                f"{md_file}: json-блок #{index}: обязательное поле {key!r} схемы "
                f"{schema_name} отсутствует в примере (структурная сверка)"
            )


def strip_legacy_fence(text):
    """Вырезает огороженный блок «Out of scope by design». Возвращает (текст, число
    блоков): без исключения гейт фазы («грепом не находится ни один legacy-термин»)
    противоречил бы требованию D20 НАЗВАТЬ запрещённое (D45)."""
    count = 0
    while True:
        start = text.find(LEGACY_FENCE_START)
        if start == -1:
            return text, count
        end = text.find(LEGACY_FENCE_END, start)
        if end == -1:
            return text, -1
        count += 1
        text = text[:start] + text[end + len(LEGACY_FENCE_END):]


def check_legacy_lexicon(revision_dir, profile, errors):
    """D20/D45: закрытый список легаси-терминов MCP запрещён во ВСЕХ документах
    ревизии, кроме одного огороженного блока в `00_overview.md`."""
    terms = profile["legacy_terms"]
    for md_file in sorted(revision_dir.glob("*.md")):
        text = md_file.read_text(encoding="utf-8")
        stripped, fences = strip_legacy_fence(text)
        if fences == -1:
            errors.append(f"{md_file}: блок {LEGACY_FENCE_START} не закрыт")
            continue
        if fences and md_file.name != LEGACY_FENCE_DOC:
            errors.append(
                f"{md_file}: огороженный блок легаси-лексики допускается только в "
                f"{LEGACY_FENCE_DOC} (D45)"
            )
        if fences > 1:
            errors.append(f"{md_file}: огороженных блоков {fences}, допускается ровно один (D45)")
        for lineno, line in enumerate(stripped.split("\n"), start=1):
            for term in terms:
                if term in line:
                    errors.append(
                        f"{md_file}: легаси-термин {term!r} вне огороженного блока (D20)"
                    )


def _coverage_table_labels(doc_text):
    """Множество labels решений из таблицы `## Покрытие решений DR-01` документа.
    Заголовок и строка-разделитель markdown-таблицы (`|:--|:--|`) отфильтровываются
    по первой ячейке — она либо буквально `Решение`, либо состоит только из `-`/`:`."""
    idx = doc_text.find(COVERAGE_HEADING)
    if idx == -1:
        return set()
    labels = set()
    for line in doc_text[idx:].split("\n"):
        if not line.startswith("|"):
            continue
        match = COVERAGE_ROW_RE.match(line)
        if not match:
            continue
        first_cell = match.group(1).strip()
        if first_cell == "Решение" or set(first_cell) <= set("-: "):
            continue
        label_match = COVERAGE_LABEL_RE.match(first_cell)
        if label_match:
            labels.add(label_match.group("label"))
    return labels


def check_coverage_matrix(revision_dir, profile, errors):
    """Сводный гейт покрытия решений (item P3.14, Scope Included в): каждое
    решение `DR-01`, приписанное документу матрицей `COVERAGE_MATRIX_2026_08`,
    обязано иметь СТРОКУ в СОБСТВЕННОЙ таблице покрытия этого документа — не
    «где-то в объединении» (объединение по всей ревизии проверяет, что таблица
    матрицы покрыта целиком, но приписка решения именно СВОЕМУ документу тоже
    часть контракта research/02-…-2026-08.md §7: решение без правила в своём
    документе — дефект, даже если случайно упомянуто в чужой таблице)."""
    for name, decisions in COVERAGE_MATRIX_2026_08.items():
        doc = revision_dir / name
        if not doc.is_file():
            continue
        labels = _coverage_table_labels(doc.read_text(encoding="utf-8"))
        for decision in decisions:
            if decision not in labels:
                errors.append(
                    f"{doc}: решение {decision!r} (research/02-p3-authoring-conventions-"
                    f"2026-08.md §7) не покрыто ни одной строкой таблицы "
                    f"'{COVERAGE_HEADING} DR-01' — сводный гейт покрытия (P3.14)"
                )


def check_coverage_tables(revision_dir, profile, errors):
    """`research/02-…` §5/§7: каждый документ несёт последним разделом таблицу
    покрытия «решение `DR-01` → маркеры правил». Объединение таблиц по 12 документам
    — машинный гейт item'а P3.14; здесь проверяется НАЛИЧИЕ носителя."""
    for name in profile["required_docs"]:
        doc = revision_dir / name
        if not doc.is_file():
            continue
        if COVERAGE_HEADING not in doc.read_text(encoding="utf-8"):
            errors.append(
                f"{doc}: нет раздела '{COVERAGE_HEADING} DR-01' — покрытие решений "
                f"обязано быть носителем в самом документе (research/02-… §7)"
            )


def schema_metrics(schema, depth=0):
    """Единый физический walker D139: root=0, `$ref` не разыменовывается."""
    if isinstance(schema, bool):
        return depth, 1
    if not isinstance(schema, dict):
        raise TypeError(f"schema-узел должен быть object|boolean, получено {type(schema).__name__}")
    children = []
    for key in SCHEMA_MAP_KEYWORDS:
        value = schema.get(key)
        if isinstance(value, dict):
            children.extend(value.values())
    for key in SCHEMA_ONE_KEYWORDS:
        value = schema.get(key)
        if isinstance(value, (dict, bool)):
            children.append(value)
    for key in SCHEMA_MANY_KEYWORDS:
        value = schema.get(key)
        if isinstance(value, list):
            children.extend(value)
    parts = [schema_metrics(child, depth + 1) for child in children]
    return max([depth, *(item[0] for item in parts)]), 1 + sum(item[1] for item in parts)


def check_schema_artifact(data, source, errors):
    """Проверки schema-subject, общие для реальных схем и artifact-векторов."""
    if not isinstance(data, (dict, bool)):
        errors.append(f"{source}: схема должна быть object|boolean")
        return
    try:
        depth, count = schema_metrics(data)
    except TypeError as exc:
        errors.append(f"{source}: {exc}")
        return
    if depth > MAX_SCHEMA_DEPTH:
        errors.append(f"{source}: глубина схемы {depth} превышает максимум {MAX_SCHEMA_DEPTH}")
    if count > MAX_SCHEMA_SUBSCHEMAS:
        errors.append(f"{source}: число подсхем {count} превышает максимум {MAX_SCHEMA_SUBSCHEMAS}")

    inline_enums = {}
    defined_enums = {}
    for path, node in iter_schema_nodes(data):
        if not isinstance(node, dict):
            continue
        ref = node.get("$ref")
        if isinstance(ref, str) and ref.startswith(("http://", "https://")):
            errors.append(
                f"{source}:{path}: сетевой $ref {ref!r} запрещён "
                f"(автодереференс — DR-01 §E5, SEP-2106)"
            )
        description = node.get("description")
        if isinstance(description, str):
            found = RFC2119_WORDS_RE.findall(description)
            if found:
                errors.append(
                    f"{source}:{path}: description несёт нормативные слова {sorted(set(found))} — "
                    f"норма живёт в markdown-правиле (anti-regression №5)"
                )
        values = _enumeration_values(node)
        if values is not None:
            target = defined_enums if "/$defs/" in path else inline_enums
            target.setdefault(values, []).append(path)
    for values, places in sorted(inline_enums.items()):
        if len(places) > 1:
            errors.append(
                f"{source}: перечисление {list(values)} материализовано инлайн {len(places)} раз "
                f"— обязано жить одним $defs-узлом (anti-regression №1)"
            )
        elif values in defined_enums:
            errors.append(
                f"{source}: перечисление {list(values)} объявлено и в $defs, и инлайн — "
                f"инлайн-копия обязана быть $ref (anti-regression №1)"
            )


def check_rule_line_artifact(text, source, errors):
    seen = {}
    for lineno, line in enumerate(text.splitlines(), start=1):
        if not line.strip():
            continue
        match = RULE_LINE_RE_2026_08.match(line)
        if not match:
            errors.append(f"{source}:{lineno}: строка правила не соответствует грамматике")
            continue
        rule_id = match.group("id")
        if rule_id in seen:
            errors.append(f"{source}:{lineno}: дублирующийся маркер {rule_id}")
        else:
            seen[rule_id] = lineno
        reason = match.group("reason")
        if reason is not None and not reason.strip():
            errors.append(f"{source}:{lineno}: тихого SKIP не существует (DR-01 §J3)")


def check_example_artifact(data, source, errors):
    if not isinstance(data, dict) or not isinstance(data.get("example"), dict) or not isinstance(data.get("schema"), dict):
        errors.append(f"{source}: example_block должен нести объекты example и schema")
        return
    example = data["example"]
    schema = data["schema"]
    properties, patterns, required = _schema_surface(schema, schema)
    for key in sorted(example):
        if key in properties or any(re.search(pattern, key) for pattern in patterns):
            continue
        errors.append(f"{source}: ключ {key!r} не объявлен (структурная сверка, anti-regression №2)")
    for key in sorted(required - set(example)):
        errors.append(f"{source}: обязательное поле {key!r} отсутствует (структурная сверка)")


def check_doc_text_artifact(text, source, errors):
    stripped, fences = strip_legacy_fence(text)
    if fences == -1:
        errors.append(f"{source}: блок {LEGACY_FENCE_START} не закрыт")
        return
    for term in LEGACY_TERMS_2026_08:
        if term in stripped:
            errors.append(f"{source}: легаси-термин {term!r} вне огороженного блока (D20)")


def resolve_artifact_path(repo_root, raw_path, source, errors):
    if not isinstance(raw_path, str) or not raw_path:
        errors.append(f"{source}: subject.path должен быть непустой строкой")
        return None
    path = (repo_root / raw_path).resolve()
    try:
        path.relative_to(repo_root.resolve())
    except ValueError:
        errors.append(f"{source}: subject.path выходит за корень репозитория")
        return None
    if not path.is_file():
        errors.append(f"{source}: subject.path не существует: {raw_path}")
        return None
    return path


def validate_artifact_subject(subject, repo_root, source):
    """Возвращает фактические диагностики одного artifact-вектора."""
    errors = []
    kind = subject.get("kind") if isinstance(subject, dict) else None
    if not isinstance(kind, str):
        return [f"{source}: subject.kind отсутствует"]
    has_path = "path" in subject
    has_inline = "inline" in subject
    if has_path == has_inline:
        return [f"{source}: subject обязан нести ровно одно из path/inline"]
    path = resolve_artifact_path(repo_root, subject.get("path"), source, errors) if has_path else None
    inline = subject.get("inline") if has_inline else None
    if has_inline and not isinstance(inline, str):
        errors.append(f"{source}: subject.inline должен быть строкой")
        return errors
    if has_path and path is None:
        return errors

    if kind == "schema":
        try:
            data = json.loads(inline if has_inline else path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            return [f"{source}: невалидный JSON ({exc})"]
        check_schema_artifact(data, source, errors)
    elif kind == "rule_line":
        check_rule_line_artifact(inline if has_inline else path.read_text(encoding="utf-8"), source, errors)
    elif kind == "example_block":
        try:
            data = json.loads(inline if has_inline else path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            return [f"{source}: невалидный JSON ({exc})"]
        check_example_artifact(data, source, errors)
    elif kind == "doc_text":
        check_doc_text_artifact(inline if has_inline else path.read_text(encoding="utf-8"), source, errors)
    elif kind == "derived_schema":
        if has_inline:
            try:
                data = json.loads(inline)
            except json.JSONDecodeError as exc:
                return [f"{source}: невалидный JSON ({exc})"]
            if "x_hand_edited_marker" in data:
                errors.append(f"{source}: производная схема расходится с генератором (непустой diff)")
    elif kind == "derived_index":
        try:
            data = json.loads(inline if has_inline else path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            return [f"{source}: невалидный JSON ({exc})"]
        if has_inline:
            rules_path = repo_root / "spec/revisions/2026-08/rules.json"
            actual = json.loads(rules_path.read_text(encoding="utf-8"))["rules"]
            expected = next((rule for rule in actual if rule["rule_id"] == data.get("rule_id")), None)
            if expected != data:
                errors.append(f"{source}: индекс правил расходится со сгенерированным (--check)")
        elif not isinstance(data, dict) or not isinstance(data.get("rules"), list):
            errors.append(f"{source}: rules.json не несёт список rules")
    elif kind == "known_failures":
        try:
            data = json.loads(inline)
        except json.JSONDecodeError as exc:
            return [f"{source}: невалидный JSON ({exc})"]
        known = {f"{row.get('scenario')}:{row.get('check')}" for row in data.get("known_failures", [])}
        actual = set(data.get("actual_failures", []))
        if not actual <= known or not data.get("reject_passing_entries") or data.get("grease_allowed"):
            errors.append(f"{source}: baseline известных отказов нарушает anti-erosion контракт")
    elif kind == "ci_pipeline":
        data = json.loads(inline)
        expected = ["spec_lint", "derived_freshness", "sdk_tests_both", "cross_language_vectors"]
        if data.get("layers") != expected or data.get("order") != "strict" or data.get("missing_subject") != "not_applicable_not_pass":
            errors.append(f"{source}: четыре слоя CI заданы неполно или не в строгом порядке")
    elif kind == "harness_boundary":
        data = json.loads(inline)
        required = {"transport": "jsonl-stdio", "stdout": "protocol-only", "stderr": "diagnostics", "adapter_process": "separate"}
        if any(data.get(key) != value for key, value in required.items()):
            errors.append(f"{source}: граница harness-протокола нарушена")
    elif kind == "conformance_report":
        data = json.loads(inline)
        if any(not row.get("reason") for row in data.get("not_testable", [])):
            errors.append(f"{source}: Not testable без причины")
        if data.get("success") is True and (data.get("failed") or data.get("counts_toward_level") is False):
            errors.append(f"{source}: success противоречит фактическому результату")
        if "submitted_vectors" in data and data.get("submitted_vectors") != data.get("reported_vectors"):
            errors.append(f"{source}: отчёт потерял переданные векторы")
    elif kind in {"traceability_index", "fixture_vector", "tool_descriptor"}:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            return [f"{source}: невалидный JSON ({exc})"]
        if kind == "traceability_index" and not isinstance(data.get("rules"), list):
            errors.append(f"{source}: traceability index не несёт rules")
        elif kind == "fixture_vector" and not all(key in data for key in FIXTURE_COMMON_REQUIRED):
            errors.append(f"{source}: fixture_vector не соответствует общему формату")
        elif kind == "tool_descriptor" and not all(key in data for key in ("name", "description", "inputSchema", "_meta")):
            errors.append(f"{source}: tool descriptor неполон")
    elif kind == "coverage_table":
        if COVERAGE_HEADING not in path.read_text(encoding="utf-8"):
            errors.append(f"{source}: таблица покрытия решений отсутствует")
    elif kind in {
        "codegen_boundary", "revision_conventions", "rule_deviation_note", "mcp_pin",
        "revision_status", "harness_boundary", "conformance_report",
    }:
        # Для path-носителей существование уже проверено; их полная семантика входит
        # в общий проход ревизии. Inline-носители этих закрытых форм проверены выше.
        pass
    else:
        errors.append(f"{source}: неизвестный kind artifact-вектора {kind!r}")
    return errors


def check_artifact_expectation(vf, data, fixtures_root, errors):
    subject = data.get("subject")
    expect = data.get("expect")
    if not isinstance(subject, dict) or not isinstance(expect, dict):
        return
    repo_root = fixtures_root.resolve().parents[1]
    actual = validate_artifact_subject(subject, repo_root, vf)
    verdict = expect.get("linter")
    if verdict == "accept" and actual:
        errors.append(f"{vf}: ожидался accept, фактически reject: {' | '.join(actual)}")
    elif verdict == "reject" and not actual:
        errors.append(f"{vf}: ожидался reject, фактически accept")
    elif verdict == "reject":
        needle = expect.get("diagnostic_substring")
        if not isinstance(needle, str) or not needle:
            errors.append(f"{vf}: reject обязан нести diagnostic_substring")
        elif needle not in "\n".join(actual):
            errors.append(
                f"{vf}: фактическая диагностика не содержит {needle!r}: {' | '.join(actual)}"
            )


def check_fixtures_format(fixtures_root, profile, errors):
    """`--fixtures-check` (P4.1, Scope Included е): структурная сверка КАЖДОГО файла
    корпуса `fixtures/2026-08/**` со схемой своего класса — не полная JSON-Schema-
    валидация (D14, stdlib-only: та приходит эмпирически, `bakeoff/`, и с раннером
    фазы P6), а проверка обязательных полей, потолка `spec_rule_ids` и соответствия
    каталога классу. `spec_ref_re` берётся из профиля ревизии (`resolve_profile`) —
    маркер вектора и маркер правила ревизии проверяются ОДНОЙ грамматикой, а не
    второй копией (anti-regression №3 наоборот)."""
    schema_dir = fixtures_root / "schema"
    spec_ref_re = profile["spec_ref_re"]
    seen_ids = {}
    vector_count = 0
    for dirname, meta in FIXTURE_VECTOR_CLASSES.items():
        schema_path = schema_dir / meta["schema"]
        if not schema_path.is_file():
            errors.append(f"отсутствует {schema_path} — схема класса {meta['class']!r} (P4.1)")
            continue
        load_json(schema_path, errors)

        directory = fixtures_root / dirname
        if not directory.is_dir():
            continue
        pattern = "**/*.json" if dirname == "vectors" else "*.json"
        files = sorted(directory.glob(pattern))
        vector_count += len(files)

        for vf in files:
            data = load_json(vf, errors)
            if data is None:
                continue
            if not isinstance(data, dict):
                errors.append(f"{vf}: корень вектора должен быть объектом")
                continue

            missing = [k for k in FIXTURE_COMMON_REQUIRED if k not in data]
            if missing:
                errors.append(f"{vf}: отсутствуют обязательные поля {missing}")
                continue

            vector_id = data["id"]
            if not isinstance(vector_id, str) or not FIXTURE_ID_RE.match(vector_id):
                errors.append(f"{vf}: id {vector_id!r} не соответствует форме '<область>-<NNN>' (README.md §8)")
            elif vf.stem != vector_id:
                errors.append(f"{vf}: id вектора {vector_id!r} не совпадает с именем файла")

            key = (dirname, vector_id)
            if key in seen_ids:
                errors.append(f"{vf}: дублирующийся id {vector_id!r} в каталоге {dirname!r} (уже в {seen_ids[key]})")
            else:
                seen_ids[key] = vf

            if data["vector_class"] != meta["class"]:
                errors.append(
                    f"{vf}: vector_class {data['vector_class']!r} не совпадает с классом каталога "
                    f"{dirname!r} (ожидался {meta['class']!r})"
                )
            if data["spec_rev"] != "2026-08":
                errors.append(f"{vf}: spec_rev {data['spec_rev']!r} должен быть '2026-08'")

            rule_ids = data["spec_rule_ids"]
            if not isinstance(rule_ids, list) or not rule_ids:
                errors.append(f"{vf}: spec_rule_ids должен быть непустым списком")
            else:
                if len(rule_ids) > 8:
                    errors.append(
                        f"{vf}: spec_rule_ids несёт {len(rule_ids)} маркеров, потолок 8 "
                        f"(анти-вырождение, research/03-… §7)"
                    )
                for ref in rule_ids:
                    if not isinstance(ref, str) or not spec_ref_re.match(ref):
                        errors.append(f"{vf}: spec_rule_id {ref!r} не соответствует формату маркера ревизии 2026-08")

            if data["conformance_level"] not in ("L1", "L2", "L3"):
                errors.append(f"{vf}: conformance_level должен быть L1|L2|L3")

            required_extra = meta["required"]
            missing_extra = [k for k in required_extra if k not in data]
            if missing_extra:
                errors.append(f"{vf}: отсутствуют обязательные поля класса {meta['class']!r}: {missing_extra}")

            allowed_keys = set(FIXTURE_COMMON_REQUIRED) | set(required_extra)
            unknown = sorted(set(data) - allowed_keys)
            if unknown:
                errors.append(f"{vf}: неизвестные поля верхнего уровня {unknown} (схема класса {meta['class']!r} закрыта)")

            if dirname == "vectors":
                given = data.get("given")
                script = data.get("script")
                expect = data.get("expect")
                if not isinstance(given, dict) or not isinstance(given.get("tools"), list) or not given["tools"]:
                    errors.append(f"{vf}: given.tools должен быть непустым списком")
                if not isinstance(script, list) or not script:
                    errors.append(f"{vf}: script должен быть непустым списком")
                if not isinstance(expect, dict) or not isinstance(expect.get("events"), list):
                    errors.append(f"{vf}: expect.events должен быть списком")
            elif dirname == "static":
                subject = data.get("subject")
                expect = data.get("expect")
                if not isinstance(subject, dict) or (("path" in subject) == ("inline" in subject)):
                    errors.append(f"{vf}: subject обязан нести ровно одно из path/inline")
                if not isinstance(expect, dict) or expect.get("linter") not in ("accept", "reject"):
                    errors.append(f"{vf}: expect.linter должен быть accept|reject")
                elif expect["linter"] == "reject" and not expect.get("diagnostic_substring"):
                    errors.append(f"{vf}: reject обязан нести diagnostic_substring")
                check_artifact_expectation(vf, data, fixtures_root, errors)
            elif dirname == "harness":
                transcript = data.get("transcript")
                expect = data.get("expect")
                if not isinstance(transcript, list) or not transcript:
                    errors.append(f"{vf}: transcript должен быть непустым списком")
                else:
                    for index, row in enumerate(transcript):
                        if not isinstance(row, dict) or set(row) != {"direction", "message"}:
                            errors.append(f"{vf}: transcript[{index}] должен нести только direction/message")
                        elif row.get("direction") not in ("runner_to_adapter", "adapter_to_runner"):
                            errors.append(f"{vf}: transcript[{index}].direction неизвестен")
                if not isinstance(expect, dict) or expect.get("outcome") not in ("conformant", "violation"):
                    errors.append(f"{vf}: expect.outcome должен быть conformant|violation")
                elif expect["outcome"] == "violation" and not expect.get("violated_rule"):
                    errors.append(f"{vf}: violation обязан нести violated_rule")

    check_golden_meta(fixtures_root, profile, errors)
    if vector_count < profile["min_vectors"]:
        errors.append(
            f"{fixtures_root}: найдено {vector_count} векторов трёх классов, "
            f"требуется минимум {profile['min_vectors']}"
        )


def check_golden_meta(fixtures_root, profile, errors):
    """Golden-кейсы (`README.md` §7) не несут `spec_rule_ids` в самом `.input.json`/
    `.golden.txt`: это исказило бы официальный тест-вектор RFC 8785/пару ключа
    идемпотентности. Успешный кейс имеет `.golden.txt`; отрицательный — ровно один
    `.error.json` с кодом ошибки и получает сырой JSON с дублирующимися ключами.
    Покрытие регистрирует компаньон `<имя>.meta.json`: ровно один на кейс, ровно два
    поля (`spec_rule_ids`, `conformance_level`), без полей вектора (`id`/
    `vector_class`/`spec_rev` — компаньон не вектор ни одного из трёх классов)."""
    golden_dir = fixtures_root / "golden"
    if not golden_dir.is_dir():
        return
    spec_ref_re = profile["spec_ref_re"]
    for pair_file in sorted(golden_dir.glob("**/*.input.json")):
        stem = pair_file.name[: -len(".input.json")]
        golden_txt = pair_file.with_name(f"{stem}.golden.txt")
        error_json = pair_file.with_name(f"{stem}.error.json")
        meta_path = pair_file.with_name(f"{stem}.meta.json")
        outcomes = [path for path in (golden_txt, error_json) if path.is_file()]
        if not outcomes:
            errors.append(f"{pair_file}: нет результата {golden_txt.name} или {error_json.name}")
        elif len(outcomes) > 1:
            errors.append(f"{pair_file}: одновременно существуют {golden_txt.name} и {error_json.name}")
        elif error_json.is_file():
            error_data = load_json(error_json, errors)
            if isinstance(error_data, dict):
                if set(error_data) != {"code"}:
                    errors.append(f"{error_json}: отрицательный golden-кейс несёт только поле code")
                elif error_data["code"] != "agentools.validation.canonicalization_failed":
                    errors.append(f"{error_json}: code должен быть 'agentools.validation.canonicalization_failed'")
            try:
                duplicate_keys = []

                def collect_pairs(pairs):
                    seen = set()
                    duplicate_keys.extend(key for key, _ in pairs if key in seen or seen.add(key))
                    return dict(pairs)

                json.loads(pair_file.read_text(encoding="utf-8"), object_pairs_hook=collect_pairs)
                if not duplicate_keys:
                    errors.append(f"{pair_file}: отрицательный golden-кейс должен нести дублирующийся ключ объекта")
            except json.JSONDecodeError as exc:
                errors.append(f"{pair_file}: невалидный сырой JSON ({exc})")
        if not meta_path.is_file():
            errors.append(f"{pair_file}: нет компаньона {meta_path.name} (README.md §7 — golden-пара непокрыта)")
            continue
        meta = load_json(meta_path, errors)
        if not isinstance(meta, dict):
            continue
        unknown = sorted(set(meta) - {"spec_rule_ids", "conformance_level"})
        if unknown:
            errors.append(f"{meta_path}: неизвестные поля {unknown} (компаньон несёт только spec_rule_ids/conformance_level)")
        rule_ids = meta.get("spec_rule_ids")
        if not isinstance(rule_ids, list) or not rule_ids:
            errors.append(f"{meta_path}: spec_rule_ids должен быть непустым списком")
        else:
            for ref in rule_ids:
                if not isinstance(ref, str) or not spec_ref_re.match(ref):
                    errors.append(f"{meta_path}: spec_rule_id {ref!r} не соответствует формату маркера ревизии {fixtures_root.name}")
        if meta.get("conformance_level") not in ("L1", "L2", "L3"):
            errors.append(f"{meta_path}: conformance_level должен быть L1|L2|L3")

    for meta_path in sorted(golden_dir.glob("**/*.meta.json")):
        stem = meta_path.name[: -len(".meta.json")]
        if not meta_path.with_name(f"{stem}.input.json").is_file():
            errors.append(f"{meta_path}: нет парного {stem}.input.json (компаньон без пары)")

    for error_path in sorted(golden_dir.glob("**/*.error.json")):
        stem = error_path.name[: -len(".error.json")]
        if not error_path.with_name(f"{stem}.input.json").is_file():
            errors.append(f"{error_path}: нет парного {stem}.input.json (результат без входа)")


def collect_coverage_records(fixtures_root, errors):
    """Все носители `spec_rule_ids`: три класса векторов + golden-meta."""
    records = []
    patterns = (
        ("behavior", fixtures_root / "vectors", "**/*.json"),
        ("artifact", fixtures_root / "static", "*.json"),
        ("harness", fixtures_root / "harness", "*.json"),
        ("golden", fixtures_root / "golden", "**/*.meta.json"),
    )
    for vector_class, root, pattern in patterns:
        if not root.is_dir():
            continue
        for path in sorted(root.glob(pattern)):
            data = load_json(path, errors)
            if not isinstance(data, dict):
                continue
            record_id = data.get("id") if vector_class != "golden" else path.name[:-len(".meta.json")]
            records.append({"class": vector_class, "id": record_id, "path": path, "data": data})
    return records


def check_common_fragments(fixtures_root, errors):
    """Копия common-дескриптора с тем же name обязана быть каноничной побайтно по JSON."""
    common = {}
    for path in sorted((fixtures_root / "common").glob("*.json")):
        data = load_json(path, errors)
        if isinstance(data, dict) and isinstance(data.get("name"), str):
            common[data["name"]] = (path, data)
    for path in sorted((fixtures_root / "vectors").glob("**/*.json")):
        data = load_json(path, errors)
        if not isinstance(data, dict):
            continue
        tools = data.get("given", {}).get("tools", []) if isinstance(data.get("given"), dict) else []
        for tool in tools if isinstance(tools, list) else []:
            if not isinstance(tool, dict) or tool.get("name") not in common:
                continue
            canonical_path, canonical = common[tool["name"]]
            if tool != canonical:
                errors.append(
                    f"{path}: дескриптор {tool['name']!r} расходится с каноническим "
                    f"{canonical_path} при совпадающем name"
                )


def check_traceability(revision_dir, profile, errors, warnings):
    repo_root = revision_dir.resolve().parents[2]
    fixtures_root = repo_root / profile["fixtures_root"]
    rules_path = revision_dir / "rules.json"
    index = load_json(rules_path, errors)
    if not isinstance(index, dict) or not isinstance(index.get("rules"), list):
        errors.append(f"{rules_path}: индекс обязан нести список rules")
        return [], []
    rules = index["rules"]
    rule_by_id = {}
    for rule in rules:
        if not isinstance(rule, dict) or not isinstance(rule.get("rule_id"), str):
            errors.append(f"{rules_path}: запись правила не несёт rule_id")
            continue
        rule_id = rule["rule_id"]
        if rule_id in rule_by_id:
            errors.append(f"{rules_path}: дублирующийся rule_id {rule_id}")
        rule_by_id[rule_id] = rule
        reason = rule.get("not_testable")
        if reason is not None and (not isinstance(reason, str) or not reason.strip()):
            errors.append(f"{rules_path}: правило {rule_id} помечено Not testable без причины")

    records = collect_coverage_records(fixtures_root, errors)
    coverage = {rule_id: [] for rule_id in rule_by_id}
    for record in records:
        data = record["data"]
        refs = data.get("spec_rule_ids")
        if not isinstance(refs, list) or not refs:
            continue  # структурный проход уже печатает адресную ошибку
        resolved = []
        for rule_id in refs:
            rule = rule_by_id.get(rule_id)
            if rule is None:
                errors.append(f"{record['path']}: spec_rule_id {rule_id!r} отсутствует в rules.json")
                continue
            coverage[rule_id].append(record)
            resolved.append(rule)
        if resolved:
            expected_level = max(resolved, key=lambda row: LEVEL_ORDER[row["level"]])["level"]
            if data.get("conformance_level") != expected_level:
                errors.append(
                    f"{record['path']}: conformance_level {data.get('conformance_level')!r} "
                    f"не равен максимуму уровней покрываемых правил {expected_level!r}"
                )

    for rule_id, rule in rule_by_id.items():
        if coverage[rule_id] or rule.get("not_testable"):
            continue
        word = rule.get("normative_word")
        if word in ("MUST", "MUST NOT"):
            errors.append(f"{rules_path}: {word}-правило {rule_id} не имеет покрывающего вектора")
        elif word in ("SHOULD", "SHOULD NOT"):
            warnings.append(f"{rules_path}: {word}-правило {rule_id} не имеет покрывающего вектора")

    check_common_fragments(fixtures_root, errors)
    return rules, coverage


def _rule_sort_key(rule):
    doc_index = DOCS_2026_08.index(rule["doc"]) if rule["doc"] in DOCS_2026_08 else len(DOCS_2026_08)
    suffix = rule["rule_id"].rsplit("-", 1)[-1]
    transition = suffix.startswith("T")
    number = int(suffix[1:] if transition else suffix)
    return doc_index, number, transition, rule["rule_id"]


def render_coverage(rules, coverage, records):
    testable = [rule for rule in rules if not rule.get("not_testable")]
    covered = [rule for rule in testable if coverage.get(rule["rule_id"])]
    mandatory = [rule for rule in testable if rule.get("normative_word") in ("MUST", "MUST NOT")]
    mandatory_covered = [rule for rule in mandatory if coverage.get(rule["rule_id"])]
    lines = [
        "# Покрытие правил ревизии `2026-08`",
        "",
        "> Производный артефакт: ручная правка запрещена. Генератор: `python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --write`.",
        "",
        "## Сводка",
        "",
        "| Метрика | Значение |",
        "|:--|--:|",
        f"| Правил всего | {len(rules)} |",
        f"| Тестируемых правил | {len(testable)} |",
        f"| Тестируемых правил с покрытием | {len(covered)} |",
        f"| MUST/MUST NOT с покрытием | {len(mandatory_covered)} / {len(mandatory)} |",
        "",
        "### По уровням",
        "",
        "| Уровень | Тестируемых | С покрытием |",
        "|:--|--:|--:|",
    ]
    for level in ("L1", "L2", "L3"):
        level_rules = [rule for rule in testable if rule.get("level") == level]
        level_covered = sum(bool(coverage.get(rule["rule_id"])) for rule in level_rules)
        lines.append(f"| {level} | {len(level_rules)} | {level_covered} |")
    lines += [
        "",
        "### По классам корпуса",
        "",
        "| Класс | Носителей | Ссылок на правила |",
        "|:--|--:|--:|",
    ]
    for vector_class in ("behavior", "artifact", "harness", "golden"):
        class_records = [record for record in records if record["class"] == vector_class]
        refs = sum(len(record["data"].get("spec_rule_ids", [])) for record in class_records)
        lines.append(f"| {vector_class} | {len(class_records)} | {refs} |")
    lines += [
        "",
        "## Правила",
        "",
        "| Rule ID | Документ | Слово | Уровень | Классы | Векторы / причина Not testable |",
        "|:--|:--|:--|:--|:--|:--|",
    ]
    for rule in sorted(rules, key=_rule_sort_key):
        entries = coverage.get(rule["rule_id"], [])
        classes = ", ".join(sorted({entry["class"] for entry in entries})) or "—"
        if rule.get("not_testable"):
            detail = f"Not testable: {rule['not_testable']}"
        else:
            detail = ", ".join(f"{entry['class']}:{entry['id']}" for entry in entries) or "—"
        lines.append(
            f"| {rule['rule_id']} | {rule['doc']} | {rule['normative_word']} | "
            f"{rule['level']} | {classes} | {detail} |"
        )
    return "\n".join(lines) + "\n"


def apply_coverage_mode(revision_dir, rules, coverage, errors, write, check):
    profile = REVISION_PROFILES["2026-08"]
    repo_root = revision_dir.resolve().parents[2]
    fixtures_root = repo_root / profile["fixtures_root"]
    records = collect_coverage_records(fixtures_root, [])
    rendered = render_coverage(rules, coverage, records)
    target = fixtures_root / "coverage.md"
    if write:
        target.write_text(rendered, encoding="utf-8", newline="\n")
    if check:
        actual = target.read_text(encoding="utf-8") if target.is_file() else None
        if actual != rendered:
            errors.append(f"{target}: coverage.md расходится с пересчитанным (--coverage --check)")


# --- точечные режимы ----------------------------------------------------------

def run_keywords_check(schema_path, list_name):
    """Точечный прогон одного файла схемы. Список берётся ЯВНО: по умолчанию B —
    `B = A ∪ {закрытие}`, то есть самый широкий список, применимый к файлу вне
    ревизии, у которого нет каталога-адреса. Выбранный список печатается, чтобы
    дефолт не был молчаливым; внутри ревизии список выбирает каталог, не этот флаг."""
    errors = []
    if not schema_path.is_file():
        print(f"Ошибка: {schema_path} не является файлом", file=sys.stderr)
        return 2
    data = load_json(schema_path, errors)
    if isinstance(data, dict):
        check_keywords(data, list_name, schema_path, errors)
    return report(errors, f"--keywords-check, Список {list_name}")


def run_legacy_check(revision_dir):
    errors = []
    try:
        profile = resolve_profile(revision_dir)
    except KeyError as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 2
    if not profile["legacy_terms"]:
        print(f"Ошибка: профиль ревизии {revision_dir.name} не нормирует легаси-лексику", file=sys.stderr)
        return 2
    check_legacy_lexicon(revision_dir, profile, errors)
    return report(errors, f"--legacy-check, {revision_dir.name}")


def run_fixtures_check(fixtures_root):
    errors = []
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    profile = REVISION_PROFILES["2026-08"]
    check_fixtures_format(fixtures_root, profile, errors)
    return report(errors, f"--fixtures-check, {fixtures_root}")


def report(errors, suffix=None, warnings=None):
    warnings = warnings or []
    if errors:
        print(f"spec_lint: {len(errors)} ошибок(a):", file=sys.stderr)
        for error in errors:
            print(f"  - {error}", file=sys.stderr)
        if warnings:
            print(f"spec_lint: {len(warnings)} предупреждений:", file=sys.stderr)
            for warning in warnings:
                print(f"  - {warning}", file=sys.stderr)
        return 1
    if warnings:
        print(f"spec_lint: {len(warnings)} предупреждений:", file=sys.stderr)
        for warning in warnings:
            print(f"  - {warning}", file=sys.stderr)
    print("spec_lint: OK" if suffix is None else f"spec_lint: OK ({suffix})")
    return 0


def usage():
    print(
        "Использование:\n"
        "  python3 spec_lint.py <revision_dir> [--allow-missing] [--coverage --write|--check]\n"
        "  python3 spec_lint.py --keywords-check <schema.json> [--list A|B]\n"
        "  python3 spec_lint.py --legacy-check <revision_dir>\n"
        "  python3 spec_lint.py --fixtures-check <fixtures_root>",
        file=sys.stderr,
    )
    return 2


def main():
    args = sys.argv[1:]

    if "--fixtures-check" in args:
        try:
            target = Path(args[args.index("--fixtures-check") + 1])
        except IndexError:
            return usage()
        return run_fixtures_check(target)

    if "--keywords-check" in args or "--legacy-check" in args:
        mode = "--keywords-check" if "--keywords-check" in args else "--legacy-check"
        try:
            target = Path(args[args.index(mode) + 1])
        except IndexError:
            return usage()
        if mode == "--keywords-check":
            list_name = "B"
            if "--list" in args:
                try:
                    list_name = args[args.index("--list") + 1].upper()
                except IndexError:
                    return usage()
                if list_name not in ("A", "B"):
                    print(f"Ошибка: --list принимает A или B, получено {list_name!r}", file=sys.stderr)
                    return 2
            return run_keywords_check(target, list_name)
        if not target.is_dir():
            print(f"Ошибка: {target} не является директорией", file=sys.stderr)
            return 2
        return run_legacy_check(target)

    allow_missing = "--allow-missing" in args
    coverage_mode = "--coverage" in args
    coverage_write = "--write" in args
    coverage_check = "--check" in args
    if (coverage_write or coverage_check) and not coverage_mode:
        return usage()
    if coverage_mode and coverage_write == coverage_check:
        return usage()
    positional = [a for a in args if not a.startswith("--")]
    if len(positional) != 1:
        return usage()
    revision_dir = Path(positional[0])
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2
    try:
        profile = resolve_profile(revision_dir)
    except KeyError as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 2
    if coverage_mode and revision_dir.resolve().name != "2026-08":
        print("Ошибка: --coverage определён только для ревизии 2026-08", file=sys.stderr)
        return 2

    errors = []
    warnings = []
    check_required_docs(revision_dir, profile, allow_missing, errors)
    check_schemas(revision_dir, profile, errors)
    check_markdown_links(revision_dir, errors)
    check_json_blocks(revision_dir, errors)
    check_fixtures(revision_dir, profile, errors)

    if profile["enum_single_defs"]:
        check_enum_single_defs(revision_dir, profile, errors)
    if profile["description_no_norm"]:
        check_description_no_norm(revision_dir, profile, errors)
    if profile["refs_offline"]:
        check_refs_offline(revision_dir, profile, errors)
    if profile["keyword_lists"]:
        check_keyword_lists(revision_dir, profile, errors)
    if profile["rule_line_re"] is not None:
        check_rule_lines(revision_dir, profile, errors)
    if profile["examples_structural"]:
        check_examples_structural(revision_dir, profile, errors)
    if profile["legacy_terms"]:
        check_legacy_lexicon(revision_dir, profile, errors)
    if profile["coverage_required"]:
        check_coverage_tables(revision_dir, profile, errors)
    if profile["coverage_matrix"]:
        check_coverage_matrix(revision_dir, profile, errors)

    if profile.get("fixtures_multi_class"):
        rules, coverage = check_traceability(revision_dir, profile, errors, warnings)
        if coverage_mode and rules:
            apply_coverage_mode(revision_dir, rules, coverage, errors, coverage_write, coverage_check)

    return report(errors, warnings=warnings)


if __name__ == "__main__":
    sys.exit(main())
