#!/usr/bin/env python3
"""spec_lint.py — проверка целостности ревизии спецификации AgenTools.

Использование:
    python3 tools/spec_lint.py <revision_dir> [--allow-missing] [--coverage --write|--check]
    python3 tools/spec_lint.py --keywords-check <schema.json> [--list A|B]
    python3 tools/spec_lint.py --legacy-check <revision_dir>
    python3 tools/spec_lint.py --fixtures-check <fixtures_root>
    python3 tools/spec_lint.py --namespace-inventory <revision_dir>
    python3 tools/spec_lint.py --namespace-inventory-selftest
    python3 tools/spec_lint.py --actionable-description-selftest <revision_dir>
    python3 tools/spec_lint.py --schema-values-selftest <revision_dir>
    python3 tools/spec_lint.py --driver-state-selftest <fixtures_root>
    python3 tools/spec_lint.py --observational-carriers-check|--observational-carriers-selftest <fixtures_root>
    python3 tools/spec_lint.py --gate-b-remediation-write|--gate-b-remediation-check <fixtures_root>

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
  9. У каждого расширяемого string `$defs` core-ветви `oneOf` не пересекаются
     с fallback-ветвью; адресный `--schema-values-selftest` исполняет probes.

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
import hashlib
import re
import sys
import tempfile
from copy import deepcopy
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
ERROR_CODE_TABLE_HEADER = "| Код | Категория | Производитель | Когда возникает |"

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

# D162/P4.16: exact semantic debt from Gate B round 2. The target set is data,
# not an id-specific branch: every listed rule must be backed by a harness-owned
# boundary observation (behavior) or independently derived checker fact (artifact).
OBSERVATIONAL_BEHAVIOR_RULES = frozenset({
    "TLP-11", "TLP-25", "ALC-2", "ALC-3", "ALC-4", "ALC-15", "ALC-18", "ALC-19",
    "ALC-20", "ALC-22", "ALC-23", "ALC-26", "ALC-28", "ALC-30", "ALC-31", "ALC-33",
    "ALC-34", "ALC-35", "ALC-36", "ALC-37", "CNF-3", "CNF-6", "CNF-8", "CLR-2",
    "CLR-8", "AOB-25", "AOB-29", "AOB-30", "AOB-31", "SEC-4", "SEC-9", "SEC-10",
    "SEC-12", "SEC-13", "SEC-14", "SEC-15", "SEC-16", "SEC-18",
})
OBSERVATIONAL_ARTIFACT_RULES = frozenset({
    "TLP-7", "TLP-12", "TLP-26", "TLP-27", "TLP-28", "TLP-30", "TLP-31", "TLP-32",
    "ALC-14", "CFH-24", "CFH-26", "CFH-51", "CFH-53",
})
OBSERVATION_KINDS = frozenset({
    "tool_boundary", "journal_projection", "audit_sink", "task_binding", "credential_boundary",
    "confirmation_boundary",
})
OBSERVATIONAL_BANNED_KEYS = frozenset({
    "projection_source", "write_back_upcast", "source_of_truth",
    "hash_input_includes_prev_hash", "chain_valid", "execution_route",
    "reject_passing_entries", "grease_allowed",
})
OBSERVATIONAL_MAGIC_RE = re.compile(r"^(?:runtime-daemon|session:|audit-record-)")
OBSERVATIONAL_ARTIFACT_KINDS = {
    "TLP-7": "tool_catalog", "TLP-12": "tool_contract_case",
    "TLP-26": "authoring_schema_profile", "TLP-27": "authoring_schema_profile",
    "TLP-28": "authoring_schema_profile", "TLP-30": "authoring_schema_profile",
    "TLP-31": "authoring_schema_profile", "TLP-32": "authoring_schema_profile",
    "ALC-14": "lifecycle_index", "CFH-24": "known_failures", "CFH-26": "known_failures",
    "CFH-51": "grease_registration", "CFH-53": "conformance_report",
}

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
        "actionable_description": False,
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
        "min_vectors": 154,
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
        "actionable_description": True,
        # D144/P3.17: owner-ветка A сохраняет namespace. Счётчики фиксируют
        # реальную цену миграции, а не прежнюю оценку «два носителя» из D46.
        "namespace_domain": "agentools.dev",
        "namespace_vendor_prefix": "dev.agentools",
        "namespace_expected": {
            "domain_file_count": 32,
            "combined_file_count": 35,
        },
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


OPEN_ENUM_CORE_KEYS = frozenset(("const", "description"))
OPEN_ENUM_FALLBACK_KEYS = frozenset(("type", "minLength", "pattern", "not", "description"))
OPEN_ENUM_NOT_FORMS = (frozenset(("enum",)), frozenset(("pattern",)))
OPEN_ENUM_DEFINITION_KEYS = frozenset(("description", "oneOf"))
OPEN_ENUM_COMBINATORS = ("oneOf", "anyOf", "allOf")
OPEN_ENUM_INVENTORY = frozenset(
    (
        ("audit-event.json", "AuditEventKind"),
        ("clarification.json", "ResultType"),
        ("decision-audit.json", "Decision"),
        ("error.json", "ErrorCode"),
        ("error.json", "ErrorCategory"),
        ("lifecycle-event.json", "ActorType"),
        ("lifecycle-event.json", "EventKind"),
        ("side-effect.json", "SideEffect"),
        ("side-effect.json", "Confirmation"),
        ("task-binding.json", "StatusChannel"),
        ("task-binding.json", "TaskStatus"),
        ("tool-descriptor.json", "ContentOrigin"),
    )
)


def _matches_open_enum_branch(branch, value):
    """Исполняет только уже проверенную каноническую форму open-enum ветви."""
    if "const" in branch:
        return value == branch["const"]
    if not isinstance(value, str) or branch.get("type") != "string":
        return False
    if "minLength" in branch and len(value) < branch["minLength"]:
        return False
    if "pattern" in branch and re.search(branch["pattern"], value) is None:
        return False
    not_node = branch.get("not", {})
    if "enum" in not_node:
        return value not in not_node["enum"]
    return "pattern" not in not_node or re.search(not_node["pattern"], value) is None


def _looks_like_open_string_definition(definition):
    """Находит незарегистрированный string candidate без доверия к `oneOf`."""
    if not isinstance(definition, dict):
        return False
    for keyword in OPEN_ENUM_COMBINATORS:
        variants = definition.get(keyword)
        if not isinstance(variants, list):
            continue
        if any(
            isinstance(branch, dict)
            and (isinstance(branch.get("const"), str) or branch.get("type") == "string")
            for branch in variants
        ):
            return True
    return False


def _format_open_enum_key(key):
    schema_name, definition_name = key
    return f"{schema_name}#/$defs/{definition_name}"


def _check_open_enum_inventory(observed, errors):
    """Exact-set gate: carrier нельзя потерять или добавить эвристикой."""
    missing = sorted(OPEN_ENUM_INVENTORY - set(observed))
    unexpected = sorted(set(observed) - OPEN_ENUM_INVENTORY)
    if missing:
        errors.append(
            "open-enum inventory missing: "
            + ", ".join(_format_open_enum_key(key) for key in missing)
        )
    if unexpected:
        errors.append(
            "open-enum inventory unexpected: "
            + ", ".join(_format_open_enum_key(key) for key in unexpected)
        )


def _open_string_definitions(revision_dir, errors):
    """Возвращает exact inventory extensible `$defs` как полные definitions.

    Производные authoring-схемы не второй источник: published-схемы проверяет
    этот gate, а `close_schema.py --check` доказывает точное наследование.
    Registry lookup не зависит от shape, поэтому `oneOf` нельзя убрать из gate.
    """
    schema_dir = revision_dir / "schema"
    observed = set()
    definitions = []
    for schema_file in sorted(schema_dir.glob("*.json")):
        data = load_json(schema_file, errors)
        if not isinstance(data, dict):
            continue
        defs = data.get("$defs")
        if not isinstance(defs, dict):
            continue
        for name, definition in defs.items():
            key = (schema_file.name, name)
            if key in OPEN_ENUM_INVENTORY:
                observed.add(key)
                definitions.append((schema_file, f"#/$defs/{name}", name, definition))
            elif _looks_like_open_string_definition(definition):
                observed.add(key)
    _check_open_enum_inventory(observed, errors)
    yield from definitions


def _check_open_string_definition(schema_file, pointer, name, definition, errors):
    """Fail-closed проверяет внешний carrier и каждую ветвь канона D147."""
    location = f"{schema_file}{pointer}"
    if not isinstance(definition, dict):
        errors.append(f"{location}: open-enum definition обязан быть object")
        return [], []

    unknown = sorted(set(definition) - OPEN_ENUM_DEFINITION_KEYS)
    missing = sorted(OPEN_ENUM_DEFINITION_KEYS - set(definition))
    if unknown:
        errors.append(f"{location}: definition содержит неизвестные keywords: {', '.join(unknown)}")
    if missing:
        errors.append(f"{location}: definition не содержит обязательные keywords: {', '.join(missing)}")
    if not isinstance(definition.get("description"), str):
        errors.append(f"{location}: definition обязан иметь string description")
    variants = definition.get("oneOf")
    if not isinstance(variants, list):
        errors.append(f"{location}: definition oneOf обязан быть list")
        return [], []

    core_branches = []
    fallbacks = []
    for index, branch in enumerate(variants):
        branch_location = f"{location}/oneOf/{index}"
        if not isinstance(branch, dict):
            errors.append(f"{branch_location}: branch обязан быть object, получено {type(branch).__name__}")
            continue
        if "const" in branch:
            if not isinstance(branch["const"], str):
                errors.append(f"{branch_location}: core const обязан быть string")
                continue
            unknown = sorted(set(branch) - OPEN_ENUM_CORE_KEYS)
            if unknown:
                errors.append(f"{branch_location}: core branch содержит неизвестные keywords: {', '.join(unknown)}")
                continue
            if not isinstance(branch.get("description"), str):
                errors.append(f"{branch_location}: core branch обязан иметь string description")
                continue
            core_branches.append(branch)
            continue

        if branch.get("type") != "string":
            errors.append(f"{branch_location}: non-string branch вне канонического open-enum shape")
            continue
        unknown = sorted(set(branch) - OPEN_ENUM_FALLBACK_KEYS)
        if unknown:
            errors.append(f"{branch_location}: fallback содержит неизвестные keywords: {', '.join(unknown)}")
            continue
        if not isinstance(branch.get("description"), str):
            errors.append(f"{branch_location}: fallback обязан иметь string description")
        if "minLength" in branch and (isinstance(branch["minLength"], bool) or not isinstance(branch["minLength"], int) or branch["minLength"] < 1):
            errors.append(f"{branch_location}: fallback minLength обязан быть integer >= 1")
        if "pattern" in branch and not isinstance(branch["pattern"], str):
            errors.append(f"{branch_location}: fallback pattern обязан быть string")
        if "not" in branch:
            not_node = branch["not"]
            if not isinstance(not_node, dict) or frozenset(not_node) not in OPEN_ENUM_NOT_FORMS:
                errors.append(f"{branch_location}/not: разрешена только форма not.enum или not.pattern")
            elif "enum" in not_node and (not isinstance(not_node["enum"], list) or not all(isinstance(value, str) for value in not_node["enum"])):
                errors.append(f"{branch_location}/not/enum: обязан быть списком string core-значений")
            elif "pattern" in not_node and not isinstance(not_node["pattern"], str):
                errors.append(f"{branch_location}/not/pattern: обязан быть string")
        if "pattern" not in branch and "not" not in branch:
            errors.append(f"{branch_location}: fallback обязан ограничиваться pattern либо not.enum")
        fallbacks.append(branch)

    core_values = [branch["const"] for branch in core_branches]
    if not core_values:
        errors.append(f"{location}: extensible string $defs обязан иметь string core const-ветви")
    elif len(set(core_values)) != len(core_values):
        errors.append(f"{location}: core const-значения повторяются: {core_values}")
    if len(fallbacks) != 1:
        errors.append(f"{location}: extensible string $defs обязан иметь ровно один string fallback, найдено {len(fallbacks)}")
    elif core_values and isinstance(fallbacks[0].get("not"), dict) and "enum" in fallbacks[0]["not"]:
        excluded = fallbacks[0]["not"]["enum"]
        if excluded != core_values:
            errors.append(f"{location}: fallback not.enum обязан в точности повторять core-набор в его порядке")

    return core_branches, fallbacks


def check_open_enum_contracts(revision_dir, errors):
    """Fail-closed проверяет полный shape каждого extensible string `$defs`."""
    definitions = list(_open_string_definitions(revision_dir, errors))
    for schema_file, pointer, name, definition in definitions:
        _check_open_string_definition(schema_file, pointer, name, definition, errors)
    return definitions


LIFECYCLE_EVENT_LIST_PREFIX = "**Виды событий.** "
LIFECYCLE_EVENT_LIST_RE = re.compile(r"^`[a-z][a-z0-9_]*`(?:, `[a-z][a-z0-9_]*`)*\.$")


def lifecycle_markdown_event_names(revision_dir, errors):
    """Извлекает единственный канонический перечень событий из документа `03`."""
    document = revision_dir / "03_action-lifecycle.md"
    try:
        lines = document.read_text(encoding="utf-8").splitlines()
    except OSError as exc:
        errors.append(f"{document}: канонический перечень EventKind недоступен ({exc})")
        return None

    headings = [index for index, line in enumerate(lines) if line.startswith(LIFECYCLE_EVENT_LIST_PREFIX)]
    if len(headings) != 1:
        errors.append(
            f"{document}: канонический перечень EventKind должен быть ровно одной строкой '**Виды событий.**'"
        )
        return None

    start = headings[0]
    items = [lines[start].removeprefix(LIFECYCLE_EVENT_LIST_PREFIX)]
    for line in lines[start + 1:]:
        if not line.startswith("`"):
            break
        items.append(line)
    items_text = " ".join(items)
    if not LIFECYCLE_EVENT_LIST_RE.fullmatch(items_text):
        errors.append(
            f"{document}: канонический перечень EventKind должен быть списком backtick-имён, разделённых запятыми"
        )
        return None

    names = re.findall(r"`([a-z][a-z0-9_]*)`", items_text)
    if len(names) != len(set(names)):
        errors.append(f"{document}: канонический перечень EventKind содержит повтор: {names}")
        return None
    return set(names)


def event_kind_schema_names(definitions, errors):
    """Возвращает core-имена `EventKind`; fallback намеренно не является core-значением."""
    entries = [entry for entry in definitions if entry[2] == "EventKind"]
    if len(entries) != 1:
        errors.append(f"schema-values: $defs.EventKind должен быть найден ровно в одной published schema, найдено {len(entries)}")
        return None
    definition = entries[0][3]
    variants = definition.get("oneOf", []) if isinstance(definition, dict) else []
    return {
        branch["const"]
        for branch in variants
        if isinstance(branch, dict) and isinstance(branch.get("const"), str)
    }


def check_lifecycle_event_contract(revision_dir, errors, definitions=None):
    """Сверяет единственные носители core EventKind в обе стороны."""
    markdown_names = lifecycle_markdown_event_names(revision_dir, errors)
    if definitions is None:
        definitions = list(_open_string_definitions(revision_dir, errors))
    schema_names = event_kind_schema_names(definitions, errors)
    if markdown_names is None or schema_names is None:
        return

    missing_in_schema = sorted(markdown_names - schema_names)
    missing_in_markdown = sorted(schema_names - markdown_names)
    if missing_in_schema:
        errors.append(f"lifecycle EventKind missing_in_schema: {', '.join(missing_in_schema)}")
    if missing_in_markdown:
        errors.append(f"lifecycle EventKind missing_in_markdown: {', '.join(missing_in_markdown)}")


def run_schema_values_selftest(revision_dir):
    """Адресный Draft 2020-12 selftest P4.14 с явной test-зависимостью."""
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2
    try:
        resolve_profile(revision_dir)
    except KeyError as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 2
    try:
        from jsonschema import Draft202012Validator
    except ImportError:
        print("Ошибка: prerequisite python-jsonschema (Draft202012Validator) недоступен", file=sys.stderr)
        return 2

    errors = []
    definitions = list(_open_string_definitions(revision_dir, errors))
    checked = []
    for schema_file, pointer, name, definition in definitions:
        core_branches, fallbacks = _check_open_string_definition(
            schema_file, pointer, name, definition, errors
        )
        variants = definition.get("oneOf", []) if isinstance(definition, dict) else []
        checked.append(
            (schema_file, pointer, name, definition, variants, core_branches, fallbacks)
        )

    required = {"ResultType", "StatusChannel", "TaskStatus"}
    found = {name for _, _, name, _ in definitions}
    missing = sorted(required - found)
    if missing:
        errors.append(f"schema-values selftest: отсутствуют regression $defs: {', '.join(missing)}")

    check_lifecycle_event_contract(revision_dir, errors, definitions)

    for schema_file, pointer, name, definition, variants, core_branches, fallbacks in checked:
        if not core_branches or len(fallbacks) != 1:
            continue
        try:
            oracle_definition = json.loads(json.dumps(definition, ensure_ascii=False))
            Draft202012Validator.check_schema(oracle_definition)
            validator = Draft202012Validator(oracle_definition)
        except Exception as exc:
            errors.append(f"{schema_file}{pointer}: Draft202012Validator не принял schema ({exc})")
            continue
        core_values = [branch["const"] for branch in core_branches]
        if any(not validator.is_valid(value) for value in core_values):
            errors.append(f"{schema_file}{pointer}: oracle отверг core probe")
        vendor_candidates = ("crm.custom", "crm.custom.value", "vendor.extension", "vendor.extension.value")
        vendor = next((candidate for candidate in vendor_candidates if validator.is_valid(candidate)), None)
        if vendor is None:
            errors.append(f"{schema_file}{pointer}: oracle не принял ни один vendor probe")
        if validator.is_valid(""):
            errors.append(f"{schema_file}{pointer}: oracle принял пустую строку")
        if not errors:
            print(f"schema values: {name}: core accepted, vendor accepted, empty rejected")

    # Branch-мутации P3.24 и outer-shape мутации P4.14 имеют независимые
    # verdicts: Draft показывает семантику, structural/inventory gate — shape.
    result_type = next((entry for entry in checked if entry[2] == "ResultType"), None)
    if result_type is None:
        errors.append("schema-values selftest: не найден $defs.ResultType для mutation probes")
    else:
        schema_file, pointer, name, definition, _, _, _ = result_type
        if isinstance(definition, dict) and isinstance(definition.get("oneOf"), list):
            max_length_definition = json.loads(json.dumps(definition, ensure_ascii=False))
            max_length_variants = max_length_definition["oneOf"]
            max_length_fallback = next(
                (
                    branch
                    for branch in max_length_variants
                    if isinstance(branch, dict)
                    and branch.get("type") == "string"
                    and "const" not in branch
                ),
                None,
            )
            if max_length_fallback is None:
                errors.append("schema-values selftest: не найден fallback ResultType для maxLength mutation")
            else:
                max_length_fallback["maxLength"] = 1
                structural_errors = []
                _check_open_string_definition(
                    schema_file, pointer, name, max_length_definition, structural_errors
                )
                oracle_rejects_vendor = not Draft202012Validator(max_length_definition).is_valid(
                    "vendor.extension"
                )
                if not oracle_rejects_vendor:
                    errors.append("schema-values selftest: maxLength mutation oracle принял vendor.extension")
                if not structural_errors:
                    errors.append("schema-values selftest: maxLength mutation structural gate не отклонил")
                if oracle_rejects_vendor and structural_errors:
                    print("schema values mutation maxLength: oracle vendor reject; structural reject")

            integer_definition = json.loads(json.dumps(definition, ensure_ascii=False))
            integer_definition["oneOf"].append({"type": "integer"})
            structural_errors = []
            _check_open_string_definition(
                schema_file, pointer, name, integer_definition, structural_errors
            )
            oracle_accepts_integer = Draft202012Validator(integer_definition).is_valid(7)
            if not oracle_accepts_integer:
                errors.append("schema-values selftest: extra integer branch oracle отверг 7")
            if not structural_errors:
                errors.append("schema-values selftest: extra integer branch structural gate не отклонил")
            if oracle_accepts_integer and structural_errors:
                print("schema values mutation extra integer branch: oracle integer accept; structural reject")

            outer_max_length = json.loads(json.dumps(definition, ensure_ascii=False))
            outer_max_length["maxLength"] = 1
            structural_errors = []
            _check_open_string_definition(
                schema_file, pointer, name, outer_max_length, structural_errors
            )
            oracle_rejects_vendor = not Draft202012Validator(outer_max_length).is_valid(
                "vendor.extension"
            )
            structural_rejects_keyword = any(
                "definition содержит неизвестные keywords: maxLength" in error
                for error in structural_errors
            )
            if not oracle_rejects_vendor:
                errors.append("schema-values selftest: outer maxLength oracle принял vendor.extension")
            if not structural_rejects_keyword:
                errors.append("schema-values selftest: outer maxLength structural gate не отклонил keyword")
            if oracle_rejects_vendor and structural_rejects_keyword:
                print("schema values mutation outer maxLength: oracle vendor reject; structural reject")

    content_origin = next((entry for entry in checked if entry[2] == "ContentOrigin"), None)
    if content_origin is None:
        errors.append("schema-values selftest: не найден $defs.ContentOrigin для allOf mutation")
    else:
        schema_file, pointer, name, definition, _, _, _ = content_origin
        if isinstance(definition, dict) and isinstance(definition.get("oneOf"), list):
            all_of_definition = json.loads(json.dumps(definition, ensure_ascii=False))
            all_of_definition["allOf"] = all_of_definition.pop("oneOf")
            structural_errors = []
            _check_open_string_definition(
                schema_file, pointer, name, all_of_definition, structural_errors
            )
            validator = Draft202012Validator(all_of_definition)
            oracle_rejects_core = not validator.is_valid("model")
            oracle_rejects_vendor = not validator.is_valid("vendor.extension")
            structural_rejects_shape = any(
                "definition содержит неизвестные keywords: allOf" in error
                for error in structural_errors
            ) and any(
                "definition не содержит обязательные keywords: oneOf" in error
                for error in structural_errors
            )
            if not oracle_rejects_core or not oracle_rejects_vendor:
                errors.append("schema-values selftest: oneOf→allOf oracle не отверг core+vendor")
            if not structural_rejects_shape:
                errors.append("schema-values selftest: oneOf→allOf structural gate не отклонил shape")
            if oracle_rejects_core and oracle_rejects_vendor and structural_rejects_shape:
                print("schema values mutation oneOf→allOf: oracle core+vendor reject; structural reject")

    inventory_errors = []
    removed_key = ("tool-descriptor.json", "ContentOrigin")
    _check_open_enum_inventory(OPEN_ENUM_INVENTORY - {removed_key}, inventory_errors)
    if not any(
        "open-enum inventory missing: tool-descriptor.json#/$defs/ContentOrigin" in error
        for error in inventory_errors
    ):
        errors.append("schema-values selftest: inventory removal gate не выявил ContentOrigin")
    else:
        print("schema values mutation inventory removal: missing ContentOrigin rejected")

    event_kind = next((entry for entry in definitions if entry[2] == "EventKind"), None)
    if event_kind is None:
        errors.append("schema-values selftest: не найден $defs.EventKind для negative probe")
    else:
        schema_file, pointer, name, definition = event_kind
        variants = definition.get("oneOf", []) if isinstance(definition, dict) else []
        broken_core = [
            branch for branch in variants
            if not (isinstance(branch, dict) and branch.get("const") == "task_bound")
        ]
        negative_errors = []
        markdown_names = lifecycle_markdown_event_names(revision_dir, negative_errors)
        if markdown_names is not None:
            missing_in_schema = sorted(
                markdown_names - {
                    branch["const"] for branch in broken_core
                    if isinstance(branch, dict) and isinstance(branch.get("const"), str)
                }
            )
            if missing_in_schema:
                negative_errors.append(
                    f"lifecycle EventKind missing_in_schema: {', '.join(missing_in_schema)}"
                )
        if not any("missing_in_schema: task_bound" in error for error in negative_errors):
            errors.append("schema-values selftest: negative EventKind probe не выявил missing_in_schema: task_bound")

    return report(
        errors,
        "schema values: exact inventory=12; full definitions Draft-valid; core=1, vendor=fallback, empty=rejected; lifecycle markdown=schema; branch+outer+inventory negatives=rejected",
    )


def check_error_code_registry(revision_dir, errors):
    """Сверяет таблицу IDE-31 с published ErrorCode без второго словаря в коде."""
    document = revision_dir / "09_idempotency-errors.md"
    schema_file = revision_dir / "schema/error.json"
    text = document.read_text(encoding="utf-8")
    table_start = text.find(ERROR_CODE_TABLE_HEADER)
    if table_start == -1:
        errors.append(f"{document}: не найдена каноническая таблица IDE-31 кодов ошибок")
        return

    table_codes = []
    for line in text[table_start:].splitlines()[2:]:
        if not line.startswith("|"):
            break
        cells = [cell.strip() for cell in line.split("|")[1:-1]]
        if len(cells) != 4:
            errors.append(f"{document}: строка таблицы IDE-31 должна нести четыре ячейки: {line}")
            continue
        code, category = cells[:2]
        if not (code.startswith("`") and code.endswith("`")):
            errors.append(f"{document}: код IDE-31 обязан быть заключён в backticks: {code!r}")
            continue
        code = code[1:-1]
        parts = code.split(".")
        if not code.startswith("agentools."):
            errors.append(f"{document}: код IDE-31 обязан принадлежать agentools.: {code!r}")
        if len(parts) != 3 or parts[1] != category.strip("`"):
            errors.append(f"{document}: категория {category!r} не согласована с кодом {code!r}")
        table_codes.append(code)

    duplicates = sorted({code for code in table_codes if table_codes.count(code) > 1})
    if duplicates:
        errors.append(f"{document}: дублирующиеся коды IDE-31: {', '.join(duplicates)}")

    schema = load_json(schema_file, errors)
    try:
        variants = schema["$defs"]["ErrorCode"]["oneOf"]
    except (TypeError, KeyError):
        errors.append(f"{schema_file}: не найден $defs/ErrorCode/oneOf")
        return
    enum_codes = [variant["const"] for variant in variants if isinstance(variant, dict) and isinstance(variant.get("const"), str)]
    missing_from_schema = sorted(set(table_codes) - set(enum_codes))
    missing_from_table = sorted(set(enum_codes) - set(table_codes))
    if missing_from_schema:
        errors.append(f"{schema_file}: коды IDE-31 отсутствуют в ErrorCode: {', '.join(missing_from_schema)}")
    if missing_from_table:
        errors.append(f"{document}: ErrorCode содержит коды вне IDE-31: {', '.join(missing_from_table)}")


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


ACTIONABLE_DESCRIPTION_POSITIVE = (
    "Purpose: Возвращает отчёт.\n"
    "Call when: Пользователь запросил отчёт.\n"
    "Required arguments: period\n"
    "Example: report_get({\"period\":\"2026-07\"})"
)
ACTIONABLE_DESCRIPTION_NEGATIVE = (
    "Purpose: Возвращает отчёт.\n"
    "Call when: Пользователь запросил отчёт.\n"
    "Required arguments: period"
)


def check_actionable_description_profile(revision_dir, profile, errors):
    """D145 проверяет СТРУКТУРУ description, а не его длину или модельное качество.

    Паттерн published-схемы — единственный исполняемый носитель формы; линтер сверяет
    с ним производную authoring-схему и прогоняет минимальную positive/negative пару.
    """
    if not profile.get("actionable_description"):
        return

    patterns = []
    for relative in ("schema/tool-descriptor.json", "schema/authoring/tool-descriptor.json"):
        schema_file = revision_dir / relative
        data = load_json(schema_file, errors)
        try:
            pattern = data["properties"]["description"]["pattern"]
        except (TypeError, KeyError):
            errors.append(f"{schema_file}: properties/description не несёт actionable pattern")
            continue
        if not isinstance(pattern, str) or not pattern:
            errors.append(f"{schema_file}: actionable pattern обязан быть непустой строкой")
            continue
        patterns.append((schema_file, pattern))

    if len(patterns) != 2:
        return
    if patterns[0][1] != patterns[1][1]:
        errors.append(
            f"{patterns[1][0]}: actionable pattern расходится с published-схемой {patterns[0][0]}"
        )
        return
    try:
        compiled = re.compile(patterns[0][1])
    except re.error as exc:
        errors.append(f"{patterns[0][0]}: actionable pattern не компилируется: {exc}")
        return
    if compiled.search(ACTIONABLE_DESCRIPTION_POSITIVE) is None:
        errors.append(f"{patterns[0][0]}: actionable positive-пример отвергнут")
    if compiled.search(ACTIONABLE_DESCRIPTION_NEGATIVE) is not None:
        errors.append(f"{patterns[0][0]}: actionable negative-пример без Example принят")


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


def _artifact_object(raw, source, errors):
    """Разбирает raw subject: проверка получает фактические значения, а не verdict-флаги."""
    try:
        value = json.loads(raw)
    except (TypeError, json.JSONDecodeError) as exc:
        errors.append(f"{source}: невалидный JSON subject ({exc})")
        return None
    if not isinstance(value, dict):
        errors.append(f"{source}: subject должен быть JSON-объектом")
        return None
    return value


def _actionable_description(value):
    return isinstance(value, str) and re.fullmatch(
        r"Purpose: [^\r\n]+\nCall when: [^\r\n]+\nRequired arguments: [^\r\n]+\nExample: [^\r\n]+",
        value,
    ) is not None


def _closed_authoring_projection(schema):
    """Small deterministic closure oracle for inline authoring-profile candidates."""
    if isinstance(schema, list):
        return [_closed_authoring_projection(value) for value in schema]
    if not isinstance(schema, dict):
        return schema
    result = {key: _closed_authoring_projection(value) for key, value in schema.items()}
    if result.get("type") == "object":
        result["additionalProperties"] = False
    return result


def _walk_schema_nodes(schema):
    if not isinstance(schema, dict):
        return
    yield schema
    for key, value in schema.items():
        if key in SCHEMA_MAP_KEYWORDS and isinstance(value, dict):
            for child in value.values():
                yield from _walk_schema_nodes(child)
        elif key in SCHEMA_ONE_KEYWORDS and isinstance(value, dict):
            yield from _walk_schema_nodes(value)
        elif key in SCHEMA_MANY_KEYWORDS and isinstance(value, list):
            for child in value:
                yield from _walk_schema_nodes(child)


def _lifecycle_transition_reference(repo_root):
    """Parse the canonical ALC-T table; fixture bytes never supply the reference."""
    text = (repo_root / "spec/revisions/2026-08/03_action-lifecycle.md").read_text(encoding="utf-8")
    rows = {}
    pattern = re.compile(r"^\| (ALC-T\d+) \| (.*?) \| `([^`]+)` \| `([^`]+)` \|", re.MULTILINE)
    for transition_id, source, event, target in pattern.findall(text):
        source_value = None if source.startswith("—") else source.strip("`")
        rows[transition_id] = {
            "transition_id": transition_id, "from": source_value, "event": event, "to": target,
        }
    return rows


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
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        rows = data.get("known_failures")
        if not isinstance(rows, list) or not rows or any(
            not isinstance(row, dict)
            or set(row) != {"scenario", "check", "rule_id", "issue"}
            or not all(isinstance(row[key], str) and row[key] for key in row)
            for row in rows
        ):
            errors.append(f"{source}: baseline entry обязан нести scenario/check/rule_id/issue-link")
            return errors
        known = {f"{row['scenario']}:{row['check']}" for row in rows}
        if any(row["scenario"].startswith("grease-") or row["check"] == "not_testable" for row in rows):
            errors.append(f"{source}: baseline не может содержать GREASE/Not testable")
        runs = data.get("runs")
        if not isinstance(runs, list) or not runs or any(
            not isinstance(run, dict) or set(run) != {"run_id", "implementation", "outcomes"}
            or run.get("implementation") not in {"php", "dart"}
            or not isinstance(run.get("run_id"), str) or not run["run_id"]
            or not isinstance(run.get("outcomes"), dict)
            for run in runs
        ):
            errors.append(f"{source}: baseline обязан нести raw run outcomes PHP/Dart")
        else:
            if any(
                any(run["outcomes"].get(key) != "failed" for run in runs)
                for key in known
            ):
                errors.append(f"{source}: healed baseline entry обязана быть ошибкой прогона")
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
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        records = data.get("vectors")
        if "rule_id" in data:
            if data.get("normative_word") not in {"MUST", "MUST NOT", "SHOULD", "SHOULD NOT", "MAY"} or data.get("outcome") == "Not testable" and (not data.get("reason") or data.get("counts_toward_level") is not False):
                errors.append(f"{source}: severity/Not testable report нарушает контракт")
            return errors
        if not isinstance(records, list) or not records or any(
            not isinstance(row, dict)
            or set(row) != {"id", "level", "outcome", "rule_ids"}
            or not isinstance(row["id"], str) or not row["id"]
            or row["level"] not in {"L1", "L2", "L3"}
            or row["outcome"] not in {"completed", "failed", "not_testable"}
            or not isinstance(row["rule_ids"], list) or not row["rule_ids"]
            for row in records
        ):
            errors.append(f"{source}: отчёт обязан нести per-vector records id/outcome/rule_ids")
            return errors
        if [row["id"] for row in records] != sorted(row["id"] for row in records):
            errors.append(f"{source}: per-vector records не отсортированы по vector_id")
        if data.get("submitted_vectors") != [row["id"] for row in records]:
            errors.append(f"{source}: отчёт потерял переданные векторы")
        failed_l3 = sorted(row["id"] for row in records if row["level"] == "L3" and row["outcome"] != "completed")
        if data.get("failed_l3") != failed_l3:
            errors.append(f"{source}: перечень непройденных L3 не выведен из per-vector outcomes")
        if any(not row.get("reason") for row in data.get("not_testable", [])):
            errors.append(f"{source}: Not testable без причины")
        if data.get("success") is True and (data.get("failed") or data.get("counts_toward_level") is False):
            errors.append(f"{source}: success противоречит фактическому результату")
        if "reported_vectors" in data and data.get("submitted_vectors") != data.get("reported_vectors"):
            errors.append(f"{source}: отчёт потерял переданные векторы")
    elif kind == "extension_identity":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        keys = {"category", "extension_id", "breaking_extension_id", "data_key"}
        if set(data) != keys or data.get("category") != "Unofficial per SEP-2133" or data.get("extension_id") != "dev.agentools/core" or data.get("breaking_extension_id") != "dev.agentools/core-v2" or not re.fullmatch(r"dev\.agentools/[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?", str(data.get("data_key"))) or re.search(r"(?:[-/]v[0-9]+)$", data.get("data_key", "")):
            errors.append(f"{source}: identity расширения расходится с контрактом EXT-1..EXT-9")
    elif kind == "extension_negotiation":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        declaration = data.get("declaration")
        if set(data) != {"schema_id", "declaration", "absent_peer", "unknown_extension", "cache_scope"} or not isinstance(data.get("schema_id"), str) or not data["schema_id"].startswith("https://agentools.dev/schema/2026-08/") or not isinstance(declaration, dict) or declaration != {"map_key": "dev.agentools/core", "specRevision": "2026-08", "scope": "per_request"} or data.get("absent_peer") != "core_or_typed_error" or data.get("unknown_extension") != "ignore" or data.get("cache_scope") != "exchange":
            errors.append(f"{source}: negotiation расширения расходится с контрактом EXT-11..EXT-17")
    elif kind == "tool_contract_case":
        data = json.loads(inline)
        required = {"name", "description", "inputSchema", "outputSchema", "annotations", "_meta"}
        annotations = data.get("annotations", {})
        action = data.get("_meta", {}).get("dev.agentools/action", {})
        if set(data) != required:
            errors.append(f"{source}: дескриптор обязан нести ровно MCP-поля профиля")
        if not re.fullmatch(r"[a-z][a-z0-9]*(_[a-z0-9]+)+", str(data.get("name", ""))) or len(data.get("name", "")) > 64:
            errors.append(f"{source}: name не соответствует профилю")
        if set(annotations) != {"readOnlyHint", "destructiveHint", "idempotentHint", "openWorldHint"}:
            errors.append(f"{source}: четыре annotations должны быть заданы явно")
        if not _actionable_description(data.get("description")):
            errors.append(f"{source}: description не соответствует actionable contract")
        if action.get("side_effect") != "write" or action.get("confirmation") is not True or annotations.get("readOnlyHint") is not False or annotations.get("destructiveHint") is not False:
            errors.append(f"{source}: annotations/_meta не согласованы с write-профилем")
        if not isinstance(action, dict) or not set(action) <= {"side_effect", "confirmation", "idempotent_commit", "permissions_hint", "content_origin"}:
            errors.append(f"{source}: action block содержит поле вне закрытого TLP-12 перечня")
        if data.get("outputSchema", {}).get("type") != "object":
            errors.append(f"{source}: outputSchema обязан описывать structuredContent")
    elif kind == "tool_catalog":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        tools = data.get("tools")
        if not isinstance(tools, list) or not tools or any(not isinstance(row, dict) for row in tools):
            errors.append(f"{source}: tool catalog обязан нести raw descriptors")
        else:
            names = [row.get("name") for row in tools]
            if any(not isinstance(name, str) or not name for name in names) or len(names) != len(set(names)):
                errors.append(f"{source}: имена инструментов каталога не уникальны")
    elif kind == "descriptor_digest_case":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        descriptor, digest_input = data.get("descriptor"), data.get("digest_input")
        keys = ["name", "description", "inputSchema", "outputSchema", "annotations"]
        if not isinstance(descriptor, dict) or not isinstance(digest_input, dict) or set(digest_input) != set(keys) or any(digest_input.get(key) != descriptor.get(key) for key in keys):
            errors.append(f"{source}: вход descriptor_digest обязан состоять ровно из пяти членов")
        if data.get("algorithm") != ["jcs", "utf8", "sha256"]:
            errors.append(f"{source}: descriptor_digest вычисляется JCS → UTF-8 → SHA-256")
        if any(key in digest_input for key in ("_meta", "title", "icons")):
            errors.append(f"{source}: во вход descriptor_digest попали ненормированные поля")
        if data.get("capture_phase") != "prepare":
            errors.append(f"{source}: digest недетерминирован или вычисляется не на prepare")
        if data.get("mismatch_code") != "agentools.precondition.descriptor_drift":
            errors.append(f"{source}: descriptor_drift подменён общей ошибкой")
    elif kind == "authoring_schema_profile":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        published, authoring = data.get("published"), data.get("authoring")
        if set(data) != {"published", "authoring"} or not isinstance(published, dict) or not isinstance(authoring, dict):
            errors.append(f"{source}: authoring schema profile расходится с TLP-26..TLP-32")
            return errors
        published_nodes = list(_walk_schema_nodes(published))
        if any(node.get("additionalProperties") is False or "unevaluatedProperties" in node for node in published_nodes):
            errors.append(f"{source}: published schema закрыта (TLP-26)")
        if authoring != _closed_authoring_projection(published):
            errors.append(f"{source}: authoring schema не является generator-derived closure (TLP-27)")
        if any("enum" in node for node in published_nodes):
            errors.append(f"{source}: extensible values заданы голым enum вместо oneOf+const+pattern (TLP-28)")
        if any(
            isinstance(node.get("patternProperties"), dict)
            and any(pattern != "^dev\\.[a-z0-9.-]+/[A-Za-z0-9._-]+$" for pattern in node["patternProperties"])
            for node in published_nodes
        ):
            errors.append(f"{source}: patternProperties применён к известным ключам (TLP-30)")
        if any("prefixItems" in node or isinstance(node.get("items"), list) for node in published_nodes):
            errors.append(f"{source}: tuple обязан быть объектом с именованными полями (TLP-31)")
        if re.search(r"(?<![A-Za-z0-9_.-])-?\d+(?:\.\d+)?[eE][+-]?\d+", inline):
            errors.append(f"{source}: exact quantity использует exponent notation (TLP-32)")
    elif kind == "lifecycle_index":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        reference = _lifecycle_transition_reference(repo_root)
        if set(data) != {"transition_id", "from", "event", "to"} or reference.get(data.get("transition_id")) != data:
            errors.append(f"{source}: новое состояние/событие не синхронизировано с матрицей ALC-14")
    elif kind == "rule_deviation_note":
        text = inline if has_inline else path.read_text(encoding="utf-8")
        if not re.search(r"уровень L[123], потому что .+", text):
            errors.append(f"{source}: отклонение уровня не содержит фразу-обоснование OVR-14")
    elif kind == "grease_registration":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        vectors = data.get("vectors")
        kinds = {row.get("kind") for row in vectors} if isinstance(vectors, list) and all(isinstance(row, dict) for row in vectors) else set()
        if kinds != {"unknown_event", "unknown_error_category", "unknown_extension_key"} or any(not isinstance(row.get("id"), str) or not row["id"] or not isinstance(row.get("value"), str) or not row["value"] for row in vectors):
            errors.append(f"{source}: GREASE registration обязан содержать три неизвестных значения")
        vector_ids = {row.get("id") for row in vectors} if isinstance(vectors, list) else set()
        runs = data.get("runs")
        if not isinstance(runs, list) or {run.get("implementation") for run in runs if isinstance(run, dict)} != {"php", "dart"} or any(
            not isinstance(run, dict) or set(run) != {"run_id", "implementation", "executed_vectors"}
            or not isinstance(run.get("run_id"), str) or not run["run_id"]
            or set(run.get("executed_vectors", [])) != vector_ids
            for run in runs or []
        ):
            errors.append(f"{source}: GREASE должен исполняться в каждом raw PHP/Dart run")
    elif kind == "tool_result_case":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        schema, result = data.get("outputSchema"), data.get("result")
        required = schema.get("required", []) if isinstance(schema, dict) else []
        if not isinstance(result, dict) or not isinstance(required, list) or any(key not in result for key in required):
            errors.append(f"{source}: structured result не соответствует outputSchema")
    elif kind == "tool_description":
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        if set(data) != {"description"} or not _actionable_description(data.get("description")):
            errors.append(f"{source}: description не соответствует actionable contract")
    elif kind in {"security_authoring", "audit_schema"}:
        data = _artifact_object(inline, source, errors)
        if data is None:
            return errors
        raw = json.dumps(data, ensure_ascii=False)
        forbidden = "delegation_claim" if kind == "security_authoring" else "gen_ai.provider.name"
        if forbidden in raw:
            errors.append(f"{source}: {kind} содержит запрещённый внешний/claim carrier {forbidden}")
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
            if dirname == "harness":
                allowed_keys.add("mcp_errors")
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
                has_cfh55 = "CFH-55" in rule_ids if isinstance(rule_ids, list) else False
                mcp_errors = data.get("mcp_errors")
                if has_cfh55:
                    expected_names = {
                        "MissingRequiredClientCapabilityError",
                        "UnsupportedProtocolVersionError",
                    }
                    if not isinstance(mcp_errors, list) or len(mcp_errors) != 2 or {
                        row.get("name") for row in mcp_errors if isinstance(row, dict)
                    } != expected_names or any(
                        not isinstance(row, dict) or set(row) != {"name", "code"} or not isinstance(row.get("code"), int)
                        for row in mcp_errors
                    ):
                        errors.append(f"{vf}: CFH-55 обязан нести точные два mcp_errors name/code")
                    elif vector_id == "hmcpcode-001" and {
                        row["name"]: row["code"] for row in mcp_errors
                    } != {"MissingRequiredClientCapabilityError": -32003, "UnsupportedProtocolVersionError": -32004}:
                        errors.append(f"{vf}: CFH-55 positive обязан нести core-коды -32003/-32004")
                elif mcp_errors is not None:
                    errors.append(f"{vf}: mcp_errors разрешён только CFH-55 harness-кейсу")

    positive = fixtures_root / "harness" / "hmcpcode-001.json"
    negative = fixtures_root / "harness" / "hmcpcodebad-001.json"
    if positive.exists() or negative.exists():
        positive_data, negative_data = load_json(positive, errors), load_json(negative, errors)
        if not isinstance(positive_data, dict) or not isinstance(negative_data, dict):
            errors.append("CFH-55 harness-пара должна состоять из двух JSON-объектов")
        else:
            def _pair_view(data):
                return {key: value for key, value in data.items() if key not in {"id", "title", "expect", "mcp_errors"}}
            if _pair_view(positive_data) != _pair_view(negative_data):
                errors.append("CFH-55 negative обязан сохранять transcript и вход positive-пары")
            pos = {row.get("name"): row.get("code") for row in positive_data.get("mcp_errors", []) if isinstance(row, dict)}
            neg = {row.get("name"): row.get("code") for row in negative_data.get("mcp_errors", []) if isinstance(row, dict)}
            changed = [name for name in pos if pos.get(name) != neg.get(name)]
            if len(changed) != 1 or set(pos) != set(neg) or negative_data.get("expect") != {"outcome": "violation", "violated_rule": "CFH-55"}:
                errors.append("CFH-55 negative обязан менять ровно один code и ждать violation/CFH-55")

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


GATE_B_MANIFEST_VERSION = 1
GATE_B_REPORTED_CARRIER_COUNT = 32
GATE_B_CARRIERS = (
    "audit-004", "cfhbaseline-001", "cfhgrease-001", "cfhreport-001",
    "clarificationstate-001", "confdual-001", "confpolicy-001", "conftoken-001",
    "extidentity-001", "extnegotiation-001", "lifecycleconcurrency-001",
    "lifecyclefold-001", "lifecycleindex-001", "lifecycleintegrity-001", "longop-002",
    "ovrdev-001", "profileuntrusted-001", "scenario-004", "scnnocode-003",
    "scnorder-002", "security-001", "security-002", "security-003", "security-004",
    "toolauthoring-001", "toolcontract-001", "tooldigest-001",
)
GATE_B_MATRIX_PATH = Path("plans/AGENTOOLS/artifacts/P5-gate-b.md")
GATE_B_FINDING_RE = re.compile(r"FAIL F[1-6]")


def _gate_b_matrix_rows(repo_root):
    """Извлекает только historical FAIL F1–F6 rows из immutable round-1 matrix.

    Matrix, а не headline «32», является единственным источником точного набора
    правил; schema/checker subjects не являются fixture carriers и поэтому сюда не
    попадают (D154)."""
    path = repo_root / GATE_B_MATRIX_PATH
    rows = []
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except OSError as exc:
        return [], [f"gate-b remediation: не прочитана round-1 matrix {path}: {exc}"]
    for line in lines:
        cells = [cell.strip() for cell in line.split("|")]
        if len(cells) < 6 or not re.fullmatch(
            r"(?:OVR|EXT|TLP|ALC|CNF|CLR|SCN|LOP|AOB|IDE|SEC|CFH)-\d+", cells[1]
        ):
            continue
        if not GATE_B_FINDING_RE.fullmatch(cells[5]):
            continue
        rows.append({"rule_id": cells[1], "source_carrier": cells[3], "finding": cells[5]})
    errors = []
    if len(rows) != 116 or len({row["rule_id"] for row in rows}) != 116:
        errors.append(f"gate-b remediation: round-1 matrix должна дать exact 116 FAIL rule ids, получено {len(rows)}")
    return rows, errors


_MISSING = object()


def _json_pointer_get(value, pointer):
    """Возвращает наблюдаемое значение либо sentinel, не стирая absence в `null`."""
    if pointer == "":
        return value
    if not isinstance(pointer, str) or not pointer.startswith("/"):
        return _MISSING
    current = value
    for token in pointer[1:].split("/"):
        token = token.replace("~1", "/").replace("~0", "~")
        if isinstance(current, dict) and token in current:
            current = current[token]
        elif isinstance(current, list) and token.isdigit() and int(token) < len(current):
            current = current[int(token)]
        else:
            return _MISSING
    return current


def _pointer_child(path, token):
    return f"{path}/{str(token).replace('~', '~0').replace('/', '~1')}"


def _input_counterfactuals(left, right, path=""):
    """Находит input-only различия, сворачивая list-tail в длину контейнера.

    `given` и `script` — единственный допустимый stimulus. Отсутствующий leaf и
    разная длина списка не маскируются выдуманным индексом: это отдельные relation.
    """
    if type(left) is not type(right):
        return [{"pointer": path, "relation": "value", "left": left, "right": right}]
    if isinstance(left, dict):
        differences = []
        for key in sorted(set(left) | set(right)):
            child = _pointer_child(path, key)
            if key not in left or key not in right:
                differences.append({
                    "pointer": child,
                    "relation": "presence",
                    "left": left.get(key, _MISSING),
                    "right": right.get(key, _MISSING),
                })
            else:
                differences.extend(_input_counterfactuals(left[key], right[key], child))
        return differences
    if isinstance(left, list):
        differences = []
        for index, (left_item, right_item) in enumerate(zip(left, right)):
            differences.extend(_input_counterfactuals(left_item, right_item, _pointer_child(path, index)))
        if len(left) != len(right):
            differences.append({"pointer": path, "relation": "length", "left": len(left), "right": len(right)})
        return differences
    return [] if left == right else [{"pointer": path, "relation": "value", "left": left, "right": right}]


def _expectation_oracle(data):
    expect = data.get("expect") if isinstance(data.get("expect"), dict) else {}
    events = expect.get("events") if isinstance(expect.get("events"), list) else []
    return {
        "final_state": expect.get("final_state"),
        "event_kinds": [event.get("kind") for event in events if isinstance(event, dict)],
    }


def _explicit_stimulus_pointers(data):
    expect = data.get("expect") if isinstance(data.get("expect"), dict) else {}
    events = expect.get("events") if isinstance(expect.get("events"), list) else []
    pointers = []
    for event in events:
        payload = event.get("payload") if isinstance(event, dict) else None
        pointer = payload.get("stimulus_pointer") if isinstance(payload, dict) else None
        if isinstance(pointer, str):
            pointers.append(pointer)
    return sorted(set(pointers))


def _pair_evidence(rule_id, records, errors):
    """Строит единственное typed evidence-отношение для behavior rule-pair."""
    members = sorted(records, key=lambda record: record["id"])
    if len(members) != 2 or any(record["class"] != "behavior" for record in members):
        errors.append(f"gate-b remediation: {rule_id} требует ровно 2 behavior member")
        return None
    left, right = members
    left_input = {key: left["data"].get(key) for key in ("given", "script")}
    right_input = {key: right["data"].get(key) for key in ("given", "script")}
    differences = _input_counterfactuals(left_input, right_input)
    if len(differences) != 1:
        errors.append(
            f"gate-b remediation: {rule_id} {left['id']}/{right['id']} должен иметь ровно один given/script counterfactual, получено {len(differences)}"
        )
        return None
    difference = differences[0]
    pointer = difference["pointer"]
    relation = difference["relation"]
    explicit = _explicit_stimulus_pointers(left["data"]) + _explicit_stimulus_pointers(right["data"])
    if explicit and any(candidate != pointer for candidate in explicit):
        errors.append(
            f"gate-b remediation: {rule_id} explicit stimulus_pointer {sorted(set(explicit))} не совпадает с {pointer}"
        )
        return None

    observations = []
    for record in members:
        value = _json_pointer_get({"given": record["data"].get("given"), "script": record["data"].get("script")}, pointer)
        observation = {"id": record["id"], "oracle": _expectation_oracle(record["data"])}
        if relation == "presence":
            observation["present"] = value is not _MISSING
            if value is not _MISSING:
                observation["value_hash"] = hashlib.sha256(_canonical_json(value).encode("utf-8")).hexdigest()
        elif relation == "length":
            if not isinstance(value, list):
                errors.append(f"gate-b remediation: {rule_id} {record['id']} length pointer {pointer} не указывает на общий list-container")
                return None
            observation["length"] = len(value)
        else:
            if value is _MISSING:
                errors.append(f"gate-b remediation: {rule_id} {record['id']} value pointer {pointer} отсутствует")
                return None
            observation["value_hash"] = hashlib.sha256(_canonical_json(value).encode("utf-8")).hexdigest()
        observations.append(observation)

    if relation == "presence" and {item["present"] for item in observations} != {False, True}:
        errors.append(f"gate-b remediation: {rule_id} presence relation не различает absent/present")
        return None
    if relation == "length" and observations[0]["length"] == observations[1]["length"]:
        errors.append(f"gate-b remediation: {rule_id} length relation не различает длины")
        return None
    if relation == "value" and observations[0]["value_hash"] == observations[1]["value_hash"]:
        errors.append(f"gate-b remediation: {rule_id} value relation не различает canonical values")
        return None
    return {"pointer": pointer, "relation": relation, "members": observations}


def _gate_b_raw_evidence(record):
    """P4.12 static/harness subjects остаются наблюдаемыми raw-contracts."""
    data = record["data"]
    if record["class"] == "artifact":
        return {"class": "artifact", "id": record["id"], "subject": data.get("subject"), "expect": data.get("expect")}
    if record["class"] == "harness":
        return {"class": "harness", "id": record["id"], "mcp_errors": data.get("mcp_errors"), "expect": data.get("expect")}
    return {"class": record["class"], "id": record["id"], "expect": data.get("expect")}


def build_gate_b_remediation_manifest(fixtures_root):
    """Строит canonical evidence-map P4.13 из matrix и наблюдаемого корпуса."""
    repo_root = fixtures_root.resolve().parents[1]
    errors = []
    matrix_rows, matrix_errors = _gate_b_matrix_rows(repo_root)
    errors.extend(matrix_errors)
    records = collect_coverage_records(fixtures_root, errors)
    by_rule = {}
    for record in records:
        for rule_id in record["data"].get("spec_rule_ids", []):
            by_rule.setdefault(rule_id, []).append(record)

    evidence = []
    for row in matrix_rows:
        replacements = sorted(by_rule.get(row["rule_id"], []), key=lambda item: (item["class"], item["id"]))
        if not replacements:
            errors.append(f"gate-b remediation: {row['rule_id']} не имеет replacement carrier")
            continue
        entry = {**row, "status": "replaced"}
        is_behavior = row["finding"] in ("FAIL F1", "FAIL F2", "FAIL F4") and row["rule_id"] not in {"SEC-17", "AOB-26"}
        if is_behavior:
            pair = _pair_evidence(row["rule_id"], replacements, errors)
            if pair is not None:
                entry["pair_evidence"] = pair
        else:
            entry["raw_evidence"] = [_gate_b_raw_evidence(record) for record in replacements]
        evidence.append(entry)

    behavior = [row for row in evidence if "pair_evidence" in row]
    static = [row for row in evidence if row["finding"] in ("FAIL F3", "FAIL F6") or row["rule_id"] in {"SEC-17", "AOB-26"}]
    scenario = [row for row in evidence if row["finding"] == "FAIL F5"]
    manifest = {
        "schema_version": GATE_B_MANIFEST_VERSION,
        "reported_carrier_count": GATE_B_REPORTED_CARRIER_COUNT,
        "round1_fixture_carriers": list(GATE_B_CARRIERS),
        "round1_fail_rules": [row["rule_id"] for row in matrix_rows],
        "driver_schema_subject": "fixtures/2026-08/schema/vector.json",
        "checker_subject": "tools/spec_lint.py",
        "partitions": {
            "behavior_rule_count": len(behavior),
            "retained_carrier": {"negative": "longop-002", "positive": "longop-102", "rule_ids": ["LOP-6", "LOP-7", "LOP-8"]},
            "scenario_rule_count": len(scenario),
            "static_rule_count": len(static),
            "static_replacements": {"SEC-17": "securityauthoring-001/002", "AOB-26": "auditschema-001/002"},
            "cfh55_pair": {"positive": "hmcpcode-001", "negative": "hmcpcodebad-001"},
        },
        "evidence": evidence,
        "gaps": [],
    }
    return manifest, errors


def _canonical_json(value):
    return json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _validate_gate_b_manifest(actual, expected):
    """Pure validator: фактический manifest обязан быть exact projection corpus+matrix."""
    if not isinstance(actual, dict):
        return ["gate-b remediation: manifest должен быть JSON object"]
    if _canonical_json(actual) != _canonical_json(expected):
        return ["gate-b remediation: manifest не является exact deterministic projection corpus+matrix"]
    return []


def _gate_b_mutation_errors(manifest):
    """Каждый мутант проходит тот же pure validator и обязан получить reject."""
    errors = []
    for finding, rule_id, mutation in (
        ("F1", "SEC-1", ("pair_evidence.pointer", "/missing-predicate")),
        ("F2", "SEC-7", ("pair_evidence.pointer", "/missing-predicate")),
        ("F3", "EXT-1", ("raw_evidence.expect", {"mutated": True})),
        ("F4", "CNF-3", ("pair_evidence.pointer", "/missing-predicate")),
        ("F5", "SCN-9", ("raw_evidence.expect", {"mutated": True})),
        ("F6", "OVR-14", ("raw_evidence.expect", {"mutated": True})),
        ("ALC-35 presence", "ALC-35", ("pair_evidence.relation", "value")),
        ("AOB-31 length", "AOB-31", ("pair_evidence.pointer", "/given/driver_state/baggage/0")),
        ("SEC-11 explicit-pointer", "SEC-11", ("pair_evidence.pointer", "/given/tools/0")),
    ):
        mutant = deepcopy(manifest)
        row = next((item for item in mutant["evidence"] if item["rule_id"] == rule_id), None)
        if row is None:
            errors.append(f"gate-b remediation mutation {finding}: отсутствует rule {rule_id}")
            continue
        target, value = mutation
        if target == "pair_evidence.pointer" and "pair_evidence" in row:
            row["pair_evidence"]["pointer"] = value
        elif target == "pair_evidence.relation" and "pair_evidence" in row:
            row["pair_evidence"]["relation"] = value
        elif target == "raw_evidence.expect" and row.get("raw_evidence"):
            row["raw_evidence"][0]["expect"] = value
        else:
            errors.append(f"gate-b remediation mutation {finding}: нет проверяемого evidence")
            continue
        rejected = _validate_gate_b_manifest(mutant, manifest)
        if not rejected:
            errors.append(f"gate-b remediation mutation {finding}: pure validator принял мутант")
        else:
            print(f"gate-b remediation mutation {finding}: expected reject ({rejected[0]})")
    return errors


def _cfh55_pair_is_distinguishing(positive, negative):
    expected_names = {"MissingRequiredClientCapabilityError", "UnsupportedProtocolVersionError"}
    positive_codes = {row.get("name"): row.get("code") for row in positive.get("mcp_errors", []) if isinstance(row, dict)}
    negative_codes = {row.get("name"): row.get("code") for row in negative.get("mcp_errors", []) if isinstance(row, dict)}
    return (
        positive_codes == {"MissingRequiredClientCapabilityError": -32003, "UnsupportedProtocolVersionError": -32004}
        and set(negative_codes) == expected_names
        and sum(positive_codes[name] != negative_codes[name] for name in expected_names) == 1
        and negative.get("expect") == {"outcome": "violation", "violated_rule": "CFH-55"}
    )


def run_gate_b_remediation_check(fixtures_root):
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    manifest_path = fixtures_root / "gate-b-remediation.json"
    manifest, errors = build_gate_b_remediation_manifest(fixtures_root)
    if manifest["round1_fixture_carriers"] != sorted(manifest["round1_fixture_carriers"]):
        errors.append("gate-b remediation: round1 fixture carriers должны быть отсортированным exact set")
    if len(manifest["round1_fixture_carriers"]) != 27:
        errors.append("gate-b remediation: ожидается exact 27 round-1 fixture carriers")
    if len(manifest["round1_fail_rules"]) != 116:
        errors.append("gate-b remediation: ожидается exact 116 round-1 FAIL rules")
    if manifest["partitions"]["behavior_rule_count"] != 65:
        errors.append("gate-b remediation: D156 требует exact 65 behavior rules")
    if not manifest_path.is_file():
        errors.append(f"gate-b remediation: отсутствует {manifest_path}")
    else:
        actual = load_json(manifest_path, errors)
        errors.extend(_validate_gate_b_manifest(actual, manifest))
    errors.extend(_gate_b_mutation_errors(manifest))
    positive = load_json(fixtures_root / "harness" / "hmcpcode-001.json", errors)
    negative = load_json(fixtures_root / "harness" / "hmcpcodebad-001.json", errors)
    if not isinstance(positive, dict) or not isinstance(negative, dict) or not _cfh55_pair_is_distinguishing(positive, negative):
        errors.append("gate-b remediation: CFH-55 exact/wrong-code pair не различается")
    else:
        mutant = deepcopy(positive)
        mutant["mcp_errors"][0]["code"] = -32002
        if _cfh55_pair_is_distinguishing(mutant, negative):
            errors.append("gate-b remediation mutation CFH-55: wrong code не был обнаружен")
        else:
            print("gate-b remediation mutation CFH-55: expected reject")
    return report(errors, "gate-b remediation: exact 27 carriers / 116 rules; 6 F-mutations + CFH-55 input checked")


def run_gate_b_remediation_write(fixtures_root):
    """Атомарно публикует единственный производный P4.13 manifest."""
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    manifest, errors = build_gate_b_remediation_manifest(fixtures_root)
    if manifest["partitions"]["behavior_rule_count"] != 65:
        errors.append("gate-b remediation: D156 требует exact 65 behavior rules")
    errors.extend(_gate_b_mutation_errors(manifest))
    if errors:
        return report(errors, "gate-b remediation: manifest не записан")
    manifest_path = fixtures_root / "gate-b-remediation.json"
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=fixtures_root, delete=False) as stream:
        stream.write(_canonical_json(manifest))
        temporary_path = Path(stream.name)
    temporary_path.replace(manifest_path)
    return report([], f"gate-b remediation: wrote only {manifest_path}")


# P4.19/D161: v2 supersedes the historical round-1 relation manifest above. The
# old builder remains readable as the evidence implementation that produced the
# append-only round-1/2 audit, while these final definitions own the CLI names.
GATE_B_SEMANTIC_MANIFEST_VERSION = 2


def _observational_finding(rule_id):
    if rule_id in {"TLP-11", "TLP-25"}:
        return "F9"
    if rule_id in {"TLP-7", "TLP-12", "TLP-26", "TLP-27", "TLP-28", "TLP-30", "TLP-31", "TLP-32", "ALC-14"}:
        return "F10"
    if rule_id.startswith("ALC-"):
        return "F11"
    if rule_id.startswith("CNF-") or rule_id.startswith("CLR-"):
        return "F12"
    if rule_id.startswith("AOB-"):
        return "F13"
    if rule_id.startswith("SEC-"):
        return "F14"
    return "F15"


def _spec_source_digest(repo_root):
    root = repo_root / "spec/revisions/2026-08"
    digest = hashlib.sha256()
    for path in sorted(p for p in root.rglob("*") if p.is_file()):
        digest.update(path.relative_to(repo_root).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def _semantic_preflight_checks(by_rule, repo_root):
    """P4.20: named sufficiency checks plus one schema-valid/raw mutant per gap class."""
    errors = []
    results = []

    def bundle(*rule_ids):
        return {
            rule_id: [deepcopy(record["data"]) for record in sorted(by_rule.get(rule_id, []), key=lambda row: row["id"])[:2]]
            for rule_id in rule_ids
        }

    def observations(data, kind):
        return [row for row in data.get("expect", {}).get("observations", []) if row.get("kind") == kind]

    def tool_call(data):
        return next(step["llm_stub"]["toolCall"] for step in data["script"] if "llm_stub" in step)

    def register(name, rule_ids, predicate, mutate):
        source = bundle(*rule_ids)
        canonical = False
        mutant_rejected = False
        try:
            canonical = bool(predicate(source))
        except (KeyError, IndexError, TypeError, ValueError, StopIteration, json.JSONDecodeError):
            canonical = False
        try:
            mutant = deepcopy(source)
            mutate(mutant)
            mutant_rejected = not bool(predicate(mutant))
        except (KeyError, IndexError, TypeError, ValueError, StopIteration, json.JSONDecodeError):
            mutant_rejected = True
        if not canonical:
            errors.append(f"semantic preflight {name}: canonical carrier не доказывает invariant")
        if canonical and not mutant_rejected:
            errors.append(f"semantic preflight {name}: адресная мутация не отвергнута")
        results.append({
            "name": name,
            "rule_ids": list(rule_ids),
            "result": "passed" if canonical and mutant_rejected else "failed",
            "mutant": "rejected" if mutant_rejected else "accepted",
        })

    confirmation_keys = ("proposal_count", "token_issue_count", "token_consume_count", "decision_event_count")

    def confirmation_layer(source):
        for rule_id in ("TLP-11", "CNF-6", "CNF-8"):
            left, right = source[rule_id]
            left_obs = observations(left, "confirmation_boundary")
            right_obs = observations(right, "confirmation_boundary")
            if len(left_obs) != 1 or left_obs != right_obs or any(left_obs[0].get(key) != 1 for key in confirmation_keys):
                return False
            if any(observations(data, "tool_boundary")[0].get("calls") != 1 for data in (left, right)):
                return False
        tlp_left, tlp_right = source["TLP-11"]
        actions = [data["given"]["tools"][0]["_meta"]["dev.agentools/action"] for data in (tlp_left, tlp_right)]
        cnf6 = [data["given"]["policy"]["rules"][0] for data in source["CNF-6"]]
        cnf8 = [data["given"]["policy"]["rules"][0] for data in source["CNF-8"]]
        return (
            {row["side_effect"] for row in actions} == {"write"}
            and {row["confirmation"] for row in actions} == {"none", "confirm"}
            and {row["verdict"] for row in cnf6} == {"allow", "ask"}
            and all(data["given"]["tools"][0]["_meta"]["dev.agentools/action"]["side_effect"] == "irreversible" for data in source["CNF-6"])
            and len({row["scope"] for row in cnf8}) == 2
            and {row["verdict"] for row in cnf8} == {"ask"}
        )

    def mutate_confirmation(source):
        observations(source["TLP-11"][0], "confirmation_boundary")[0]["token_consume_count"] = 0

    register("confirmation-layer-boundary", ("TLP-11", "CNF-6", "CNF-8"), confirmation_layer, mutate_confirmation)

    allowed_operations = {"prompts/get", "resources/read", "tools/call", "tasks/result"}

    def clarification_operations(source):
        rows = source["CLR-8"]
        operations = [observations(data, "task_binding")[0]["input_required_operations"] for data in rows]
        return all(set(items) <= allowed_operations for items in operations) and {tuple(items) for items in operations} == {(), ("tasks/result",)}

    register(
        "clarification-protocol-operations", ("CLR-8",), clarification_operations,
        lambda source: observations(source["CLR-8"][0], "task_binding")[0].update(input_required_operations=[]),
    )

    def mapping_registry(source):
        rows = source["AOB-25"]
        registered = [row["given"]["driver_state"]["audit_export"]["registered_mappings"] for row in rows]
        selected = [row["given"]["driver_state"]["audit_export"]["mapping_version"] for row in rows]
        membership = [value in {entry["version"] for entry in registry} for value, registry in zip(selected, registered)]
        counts = [observations(row, "audit_sink")[0]["record_count"] for row in rows]
        return registered[0] == registered[1] and membership == [False, True] and counts == [0, 1]

    register(
        "audit-independent-mapping-registry", ("AOB-25",), mapping_registry,
        lambda source: source["AOB-25"][1]["given"]["driver_state"]["audit_export"].pop("registered_mappings"),
    )

    def wire_bytes(entries):
        return len(",".join(f"{entry['key']}={entry['value']}" for entry in entries).encode("utf-8"))

    def baggage_wire_limit(source):
        rows = source["AOB-31"]
        measures = [(len(row["given"]["driver_state"]["baggage"]), wire_bytes(row["given"]["driver_state"]["baggage"])) for row in rows]
        sinks = [observations(row, "audit_sink")[0] for row in rows]
        return (
            measures == [(65, 8195), (64, 8192)]
            and sinks[0]["record_count"] == 0 and sinks[0]["attribute_names"] == []
            and sinks[1]["record_count"] == 1 and sinks[1]["baggage_bytes"] == 8192
        )

    register(
        "baggage-wire-byte-boundary", ("AOB-31",), baggage_wire_limit,
        lambda source: observations(source["AOB-31"][1], "audit_sink")[0].update(baggage_bytes=8191),
    )

    def derivation_lineage(source):
        rows = source["SEC-9"]
        origins = []
        for row in rows:
            provenance = row["given"]["driver_state"]["provenance"][0]
            derivation = row["given"]["driver_state"]["derivations"][0]
            args = tool_call(row)["args"]
            if derivation["source_address"] != provenance["address"] or derivation["target_address"].split(".")[-1] not in args:
                return False
            origins.append(provenance["content_origin"])
        return set(origins) == {"untrusted_external", "trusted.local"}

    register(
        "untrusted-derivation-lineage", ("SEC-9",), derivation_lineage,
        lambda source: source["SEC-9"][0]["given"]["driver_state"].pop("derivations"),
    )

    def per_call_authorization(source):
        rows = source["SEC-16"]
        request_ids = [tool_call(row)["call_id"] for row in rows]
        grants = [row["given"]["driver_state"]["authorization"] for row in rows]
        spies = [observations(row, "tool_boundary")[0] for row in rows]
        return (
            request_ids == ["call-1", "call-1"]
            and [grant["call_id"] == request for grant, request in zip(grants, request_ids)] == [False, True]
            and [spy["call_ids"] for spy in spies] == [[], ["call-1"]]
            and [spy["calls"] for spy in spies] == [0, 1]
        )

    register(
        "authorization-per-call-binding", ("SEC-16",), per_call_authorization,
        lambda source: tool_call(source["SEC-16"][1]).pop("call_id"),
    )

    def append_adapter(source):
        for rule_id in ("ALC-20", "ALC-22", "ALC-23"):
            rows = source[rule_id]
            matches = []
            for row in rows:
                state = row["given"]["driver_state"]
                if len(state["journal"]) != 1 or state["journal"][0]["seq"] != 1:
                    return False
                probe = state["append_probe"]
                event = probe["attempted_event"]
                matches.append(event["expected_prev_seq"] == probe["storage_last_seq"])
                if event["seq"] != 2 or observations(row, "journal_projection")[0]["source_event_ids"] != ["ev-1"]:
                    return False
            if matches != [False, True]:
                return False
        return True

    register(
        "append-adapter-conflict-stimulus", ("ALC-20", "ALC-22", "ALC-23"), append_adapter,
        lambda source: source["ALC-20"][0]["given"]["driver_state"].pop("append_probe"),
    )

    def event_hash(event, include_prev_hash=False):
        value = event if include_prev_hash else {key: child for key, child in event.items() if key != "prev_hash"}
        encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return "sha-256:" + hashlib.sha256(encoded).hexdigest()

    def hash_composition(source):
        negative, positive = source["ALC-36"]
        for row in (negative, positive):
            journal = row["given"]["driver_state"]["journal"]
            if len(journal) != 3 or journal[1]["prev_hash"] != event_hash(journal[0]):
                return False
        neg_journal = negative["given"]["driver_state"]["journal"]
        pos_journal = positive["given"]["driver_state"]["journal"]
        return neg_journal[2]["prev_hash"] == event_hash(neg_journal[1], True) and pos_journal[2]["prev_hash"] == event_hash(pos_journal[1])

    register(
        "journal-hash-excludes-own-prev-hash", ("ALC-36",), hash_composition,
        lambda source: source["ALC-36"][1]["given"]["driver_state"]["journal"].pop(),
    )

    def baseline_every_run(source):
        negative, positive = source["CFH-24"]
        neg_data = json.loads(negative["subject"]["inline"])
        pos_data = json.loads(positive["subject"]["inline"])
        key = "longop-002:notification-channel"
        neg_statuses = [run["outcomes"].get(key) for run in neg_data["runs"]]
        pos_statuses = [run["outcomes"].get(key) for run in pos_data["runs"]]
        return (
            "passed" in neg_statuses and "failed" in neg_statuses
            and all(status == "failed" for status in pos_statuses)
            and bool(validate_artifact_subject(negative["subject"], repo_root, negative["id"]))
            and not validate_artifact_subject(positive["subject"], repo_root, positive["id"])
        )

    def mutate_baseline(source):
        negative = source["CFH-24"][0]
        data = json.loads(negative["subject"]["inline"])
        for run in data["runs"]:
            run["outcomes"]["longop-002:notification-channel"] = "failed"
        negative["subject"]["inline"] = json.dumps(data, separators=(",", ":"))

    register("known-failure-every-run", ("CFH-24",), baseline_every_run, mutate_baseline)

    def append_attempt_accounting(source):
        for rule_id in ("ALC-19", "ALC-26"):
            for row in source[rule_id]:
                observation = observations(row, "journal_projection")[0]
                if not observation["emitted_event_kinds"] or observation["append_attempts"] < len(observation["emitted_event_kinds"]):
                    return False
        return True

    register(
        "journal-emission-append-accounting", ("ALC-19", "ALC-26"), append_attempt_accounting,
        lambda source: observations(source["ALC-19"][0], "journal_projection")[0].update(append_attempts=0),
    )

    def empty_rejected_audit_sink(source):
        for rule_id in ("AOB-25", "AOB-29", "AOB-30", "AOB-31"):
            for row in source[rule_id]:
                sink = observations(row, "audit_sink")[0]
                if sink["record_count"] == 0 and sink["attribute_names"]:
                    return False
        return True

    register(
        "rejected-audit-sink-is-empty", ("AOB-25", "AOB-29", "AOB-30", "AOB-31"), empty_rejected_audit_sink,
        lambda source: observations(source["AOB-25"][0], "audit_sink")[0].update(attribute_names=["gen_ai.provider.name"]),
    )

    return results, errors


def build_gate_b_semantic_manifest(fixtures_root):
    """Build exact 51-rule observational projection and execute every mutant."""
    repo_root = fixtures_root.resolve().parents[1]
    errors = []
    records = collect_coverage_records(fixtures_root, errors)
    by_rule = {}
    for record in records:
        for rule_id in record["data"].get("spec_rule_ids", []):
            by_rule.setdefault(rule_id, []).append(record)
    target = sorted(OBSERVATIONAL_BEHAVIOR_RULES | OBSERVATIONAL_ARTIFACT_RULES)
    evidence = []
    validator = None
    try:
        validator = _fixture_vector_validator(fixtures_root)
    except Exception as exc:
        errors.append(f"gate-b semantic manifest: offline vector validator недоступен ({exc})")
    for rule_id in target:
        expected_class = "behavior" if rule_id in OBSERVATIONAL_BEHAVIOR_RULES else "artifact"
        members = sorted(
            [record for record in by_rule.get(rule_id, []) if record["class"] == expected_class],
            key=lambda record: record["id"],
        )
        if len(members) != 2:
            errors.append(f"gate-b semantic manifest: {rule_id} требует exact 2 {expected_class} carriers, получено {len(members)}")
            continue
        shortcuts = sorted({
            path for member in members for path in _walk_observational_shortcuts(member["data"])
        })
        if shortcuts:
            errors.append(f"gate-b semantic manifest: {rule_id} содержит shortcuts {shortcuts}")
        row = {
            "rule_id": rule_id,
            "finding": _observational_finding(rule_id),
            "carrier_class": expected_class,
            "carrier_ids": [member["id"] for member in members],
            "banned_shortcuts": shortcuts,
        }
        if expected_class == "behavior":
            pair = _pair_evidence(rule_id, members, errors)
            observations = []
            for member in members:
                if validator is not None:
                    schema_errors = list(validator.iter_errors(member["data"]))
                    if schema_errors:
                        errors.append(f"gate-b semantic manifest: {member['id']} schema-invalid ({schema_errors[0].message})")
                ownership_errors = []
                _check_behavior_observation_ownership(member["data"], member["id"], ownership_errors)
                errors.extend(ownership_errors)
                kinds = sorted({item.get("kind") for item in member["data"].get("expect", {}).get("observations", [])})
                observations.append({
                    "carrier_id": member["id"],
                    "classes": kinds,
                    "oracle_sha256": hashlib.sha256(_canonical_json(member["data"].get("expect")).encode("utf-8")).hexdigest(),
                })
            mutant = deepcopy(members[0]["data"])
            mutant.get("expect", {}).pop("observations", None)
            mutant_errors = []
            _check_behavior_observation_ownership(mutant, f"mutant:{rule_id}", mutant_errors)
            if not mutant_errors:
                errors.append(f"gate-b semantic manifest: {rule_id} self-report mutant не отвергнут")
            row.update({
                "stimulus": pair,
                "observations": observations,
                "mutant": {"id": f"self-report:{rule_id}", "result": "rejected", "diagnostic_count": len(mutant_errors)},
            })
        else:
            negative = [member for member in members if member["data"].get("expect", {}).get("linter") == "reject"]
            positive = [member for member in members if member["data"].get("expect", {}).get("linter") == "accept"]
            if len(negative) != 1 or len(positive) != 1:
                errors.append(f"gate-b semantic manifest: {rule_id} требует raw negative + positive")
                continue
            positive_errors = validate_artifact_subject(positive[0]["data"].get("subject"), repo_root, positive[0]["id"])
            negative_errors = validate_artifact_subject(negative[0]["data"].get("subject"), repo_root, negative[0]["id"])
            if positive_errors or not negative_errors:
                errors.append(f"gate-b semantic manifest: {rule_id} raw checker pair не различается")
            row.update({
                "stimulus": {
                    "negative_subject_sha256": hashlib.sha256(negative[0]["data"]["subject"]["inline"].encode("utf-8")).hexdigest(),
                    "positive_subject_sha256": hashlib.sha256(positive[0]["data"]["subject"]["inline"].encode("utf-8")).hexdigest(),
                },
                "observations": [{
                    "carrier_id": positive[0]["id"],
                    "classes": [f"checker_derived:{positive[0]['data']['subject']['kind']}"],
                    "oracle_sha256": hashlib.sha256(_canonical_json(positive[0]["data"].get("expect")).encode("utf-8")).hexdigest(),
                }],
                "mutant": {"id": f"raw-fact:{rule_id}", "result": "rejected", "diagnostic_count": len(negative_errors)},
            })
        evidence.append(row)
    semantic_preflight, semantic_errors = _semantic_preflight_checks(by_rule, repo_root)
    errors.extend(semantic_errors)
    present = {row["rule_id"] for row in evidence}
    gaps = sorted(set(target) - present)
    manifest = {
        "schema_version": GATE_B_SEMANTIC_MANIFEST_VERSION,
        "generated_by": "tools/spec_lint.py --gate-b-remediation-write",
        "source_tree_sha256": _spec_source_digest(repo_root),
        "checker_subject": "tools/spec_lint.py",
        "observation_schema_subject": "fixtures/2026-08/schema/vector.json",
        "partitions": {"behavior": 38, "artifact": 13, "total": 51},
        "evidence": evidence,
        "gaps": gaps,
        "mutation_reject_count": sum(row.get("mutant", {}).get("result") == "rejected" for row in evidence),
        "semantic_preflight": {
            "checks": semantic_preflight,
            "passed": sum(row["result"] == "passed" for row in semantic_preflight),
            "mutation_reject_count": sum(row["mutant"] == "rejected" for row in semantic_preflight),
        },
    }
    if len(evidence) != 51 or gaps:
        errors.append(f"gate-b semantic manifest: exact 51 нарушен (evidence={len(evidence)}, gaps={gaps})")
    return manifest, errors


def _semantic_manifest_pure_mutations(manifest):
    errors = []
    rejected = 0
    for index, row in enumerate(manifest.get("evidence", [])):
        mutant = deepcopy(manifest)
        mutant["evidence"][index]["mutant"]["result"] = "accepted"
        if _validate_gate_b_manifest(mutant, manifest):
            rejected += 1
        else:
            errors.append(f"gate-b semantic manifest: pure mutation принят для {row.get('rule_id')}")
    if rejected != 51:
        errors.append(f"gate-b semantic manifest: pure mutation rejects {rejected}/51")
    return errors


def run_gate_b_remediation_check(fixtures_root):
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    manifest, errors = build_gate_b_semantic_manifest(fixtures_root)
    if manifest["partitions"] != {"behavior": 38, "artifact": 13, "total": 51}:
        errors.append("gate-b semantic manifest: partition должен быть 38+13=51")
    if manifest["mutation_reject_count"] != 51:
        errors.append("gate-b semantic manifest: требуется 51 addressable mutation reject")
    errors.extend(_semantic_manifest_pure_mutations(manifest))
    path = fixtures_root / "gate-b-remediation.json"
    if not path.is_file():
        errors.append(f"gate-b semantic manifest: отсутствует {path}")
    else:
        actual = load_json(path, errors)
        errors.extend(_validate_gate_b_manifest(actual, manifest))
    return report(errors, "gate-b semantic manifest v2: exact 51 (38 behavior + 13 artifact), 51 mutants rejected")


def run_gate_b_remediation_write(fixtures_root):
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    manifest, errors = build_gate_b_semantic_manifest(fixtures_root)
    errors.extend(_semantic_manifest_pure_mutations(manifest))
    if errors:
        return report(errors, "gate-b semantic manifest v2 не записан")
    path = fixtures_root / "gate-b-remediation.json"
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=fixtures_root, delete=False) as stream:
        stream.write(_canonical_json(manifest))
        temporary_path = Path(stream.name)
    temporary_path.replace(path)
    return report([], f"gate-b semantic manifest v2: wrote only {path}")


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

def collect_namespace_inventory(revision_dir, domain, vendor_prefix):
    """Возвращает сортированный реестр текстовых carriers двух namespace-значений.

    Считаются файлы, а не вхождения: миграция обязана охватить каждый физический
    носитель. Нечитаемый UTF-8 файл не игнорируется молча — это дефект ревизии.
    """
    domain_files = []
    vendor_files = []
    for path in sorted(revision_dir.rglob("*")):
        if not path.is_file() or path.is_symlink():
            continue
        text = path.read_text(encoding="utf-8")
        relative = path.relative_to(revision_dir).as_posix()
        if domain in text:
            domain_files.append(relative)
        if vendor_prefix in text:
            vendor_files.append(relative)

    combined_files = sorted(set(domain_files) | set(vendor_files))
    return {
        "combined": {
            "file_count": len(combined_files),
            "files": combined_files,
        },
        "domain": {
            "file_count": len(domain_files),
            "files": domain_files,
            "value": domain,
        },
        "vendor_prefix": {
            "file_count": len(vendor_files),
            "files": vendor_files,
            "value": vendor_prefix,
        },
    }


def run_namespace_inventory(revision_dir):
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2
    try:
        profile = resolve_profile(revision_dir)
    except KeyError as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 2

    domain = profile.get("namespace_domain")
    vendor_prefix = profile.get("namespace_vendor_prefix")
    expected = profile.get("namespace_expected")
    if not domain or not vendor_prefix or not expected:
        print(f"Ошибка: профиль ревизии {revision_dir.name} не пиннит namespace", file=sys.stderr)
        return 2

    try:
        inventory = collect_namespace_inventory(revision_dir, domain, vendor_prefix)
    except (OSError, UnicodeError) as exc:
        print(f"Ошибка inventory: {exc}", file=sys.stderr)
        return 2

    counts = {
        "domain_file_count": inventory["domain"]["file_count"],
        "vendor_file_count": inventory["vendor_prefix"]["file_count"],
        "combined_file_count": inventory["combined"]["file_count"],
    }
    actual = {key: counts[key] for key in expected}
    inventory["expected"] = expected
    inventory["matches_expected"] = actual == expected
    print(json.dumps(inventory, ensure_ascii=False, indent=2, sort_keys=True))
    if actual != expected:
        print(
            f"namespace inventory расходится с пином: ожидалось {expected}, получено {actual}",
            file=sys.stderr,
        )
        return 1
    return 0


def namespace_inventory_selftest():
    """Минимальное дерево различает domain-only, vendor-only и оба значения."""
    with tempfile.TemporaryDirectory() as temp_dir:
        root = Path(temp_dir)
        fixtures = {
            "z-domain-only.txt": "https://agentools.dev/schema/example.json\n",
            "a-vendor-only.txt": "dev.agentools/action\n",
            "m-both.txt": "agentools.dev\ndev.agentools/core\n",
            "n-neither.txt": "example.invalid\n",
        }
        for name, content in fixtures.items():
            (root / name).write_text(content, encoding="utf-8", newline="\n")
        actual = collect_namespace_inventory(root, "agentools.dev", "dev.agentools")

    expected = {
        "combined": {
            "file_count": 3,
            "files": ["a-vendor-only.txt", "m-both.txt", "z-domain-only.txt"],
        },
        "domain": {
            "file_count": 2,
            "files": ["m-both.txt", "z-domain-only.txt"],
            "value": "agentools.dev",
        },
        "vendor_prefix": {
            "file_count": 2,
            "files": ["a-vendor-only.txt", "m-both.txt"],
            "value": "dev.agentools",
        },
    }
    if actual != expected:
        print(f"namespace inventory selftest: FAIL\nexpected={expected}\nactual={actual}", file=sys.stderr)
        return 1
    print("namespace inventory selftest: OK (domain-only/vendor-only/both)")
    return 0


def run_actionable_description_selftest(revision_dir):
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2
    try:
        profile = resolve_profile(revision_dir)
    except KeyError as exc:
        print(f"Ошибка: {exc}", file=sys.stderr)
        return 2
    errors = []
    check_actionable_description_profile(revision_dir, profile, errors)
    return report(errors, "actionable description: positive accepted; negative rejected")


def run_driver_state_selftest(fixtures_root):
    """P4.9: проверяет Draft 2020-12 oracle для каждой новой driver-секции.

    `--fixtures-check` сознательно остаётся stdlib-структурной проверкой корпуса. Этот
    адресный selftest использует тот же published/fixture schema graph, что и внешний
    offline validator, и печатает для каждой секции число ошибок positive/negative
    probe. Так закрытый driver wrapper не может незаметно превратиться в свободный
    state, а external `$ref` остаются частью реально исполняемой проверки.
    """
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    try:
        from jsonschema import Draft202012Validator, RefResolver
    except ImportError:
        print("Ошибка: prerequisite python-jsonschema (Draft202012Validator) недоступен", file=sys.stderr)
        return 2

    schema_path = fixtures_root / "schema" / "vector.json"
    tool_path = fixtures_root / "common" / "tool-read.json"
    repo_root = fixtures_root.resolve().parents[1]
    errors = []
    try:
        schema = json.loads(schema_path.read_text(encoding="utf-8"))
        tool = json.loads(tool_path.read_text(encoding="utf-8"))
        # Контрольная проба использует ровно committed bytes канонического descriptor:
        # подмена description в памяти скрыла бы расхождение common-фрагмента с корпусом.
        store = {}
        for path in sorted((fixtures_root / "schema").glob("*.json")):
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data.get("$id"), str):
                store[data["$id"]] = data
        for path in sorted((repo_root / "spec" / "revisions" / "2026-08" / "schema").glob("*.json")):
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data.get("$id"), str):
                store[data["$id"]] = data
        Draft202012Validator.check_schema(schema)
        resolver = RefResolver(base_uri=schema["$id"], referrer=schema, store=store)
        validator = Draft202012Validator(schema, resolver=resolver)
    except Exception as exc:
        return report([f"driver-state selftest: не удалось собрать offline validator ({exc})"])

    base = {
        "id": "driverstate-000",
        "title": "Контрольная проба driver state (не входит в корпус)",
        "vector_class": "behavior",
        "spec_rev": "2026-08",
        "spec_rule_ids": ["SEC-7"],
        "conformance_level": "L1",
        "given": {"tools": [tool]},
        "script": [{"user_input": "проверка driver state"}],
        "expect": {"events": [], "final_state": "completed"},
    }
    sections = {
        "principals": {
            "initiator": {"id": "user-1"}, "agent": {"id": "agent-1"}, "authorizer": {"id": "auth-1"},
        },
        "authorization": {"resource": "tenant:1", "tool": "invoice_read", "permissions": ["invoice.read"]},
        "credentials": [{
            "kind": "access_token", "source": "trusted_credential", "issuer": "issuer-1",
            "audience": "agentools", "integrity": "verified", "principal_id": "user-1", "scopes": ["invoice.read"],
        }],
        "provenance": [{"address": "/args/customer_id", "content_origin": "untrusted_external"}],
        "baggage": [{"key": "trace", "value": "abc"}],
        "journal": [{
            "event_id": "event-1", "action_id": "action-1", "seq": 1, "expected_prev_seq": 0,
            "ts": "2026-08-04T00:00:00Z", "actor": {"type": "user", "id": "user-1"},
            "kind": "action_created", "event_version": 1, "spec_rev": "2026-08", "payload": {},
        }],
        "effects": [{"action_id": "action-1", "effects": {}}],
        "tool_results": [{"tool": "invoice_read", "content": "ok", "is_error": False}],
        "task_channel": [{"task_id": "task-1", "channel": "poll", "status": "working", "message": "started"}],
        "confirmation": {
            "proposal_id": "proposal-1", "proposal_version": 1, "token_id": "token-1",
            "covered_principal_id": "user-1", "prepared_descriptor_digest": "digest-a",
            "current_descriptor_digest": "digest-a", "prepared_args_fingerprint": "args-a",
            "commit_args_fingerprint": "args-a",
        },
        "audit_export": {
            "adapter_id": "otel", "mapping_version": "v1", "boundary": "external",
            "attributes": ["gen_ai.provider.name", "input_tokens"], "audit_fields": ["action_id"],
        },
    }
    negative_paths = {
        "principals": ("initiator", "unexpected"),
        "authorization": (None, "unexpected"),
        "credentials": (0, "unexpected"),
        "provenance": (0, "unexpected"),
        "baggage": (0, "unexpected"),
        "journal": (0, "event_id"),
        "effects": (0, "action_id"),
        "tool_results": (0, "unexpected"),
        "task_channel": (0, "unexpected"),
        "confirmation": (None, "unexpected"),
        "audit_export": (None, "unexpected"),
    }
    for name, value in sections.items():
        positive = json.loads(json.dumps(base, ensure_ascii=False))
        positive["given"]["driver_state"] = {name: value}
        positive_errors = list(validator.iter_errors(positive))

        negative = json.loads(json.dumps(positive, ensure_ascii=False))
        index, key = negative_paths[name]
        target = negative["given"]["driver_state"][name]
        if name in ("journal", "effects"):
            target[index].pop(key)
        elif index is None:
            target[key] = "forbidden"
        elif isinstance(index, str):
            target[index][key] = "forbidden"
        else:
            target[index][key] = "forbidden"
        negative_errors = list(validator.iter_errors(negative))
        if positive_errors or not negative_errors:
            errors.append(
                f"driver-state selftest {name}: valid={len(positive_errors)}, invalid={len(negative_errors)}"
            )
        else:
            print(f"driver-state {name}: valid=0, invalid={len(negative_errors)}")

    policy_positive = json.loads(json.dumps(base, ensure_ascii=False))
    policy_positive["given"]["policy"] = {"rules": [{"scope": "tenant", "verdict": "ask"}]}
    policy_negative = json.loads(json.dumps(policy_positive, ensure_ascii=False))
    policy_negative["given"]["policy"]["rules"][0]["verdict"] = "bypass"
    policy_ok = list(validator.iter_errors(policy_positive))
    policy_bad = list(validator.iter_errors(policy_negative))
    if policy_ok or not policy_bad:
        errors.append(f"driver-state policy: valid={len(policy_ok)}, invalid={len(policy_bad)}")
    else:
        print(f"driver-state policy: valid=0, invalid={len(policy_bad)}")

    for name, step in {
        "script.tool_result": {"tool_result": sections["tool_results"][0]},
        "script.task_message": {"task_message": sections["task_channel"][0]},
    }.items():
        positive = json.loads(json.dumps(base, ensure_ascii=False))
        positive["script"] = [step]
        positive_errors = list(validator.iter_errors(positive))
        negative = json.loads(json.dumps(positive, ensure_ascii=False))
        negative["script"][0].update({"user_input": "second stimulus"})
        negative_errors = list(validator.iter_errors(negative))
        if positive_errors or not negative_errors:
            errors.append(
                f"driver-state selftest {name}: valid={len(positive_errors)}, invalid={len(negative_errors)}"
            )
        else:
            print(f"driver-state {name}: valid=0, invalid={len(negative_errors)}")

    return report(errors, "driver-state: 12 sections + policy + 2 script steps; valid=0, invalid>0")


def _fixture_vector_validator(fixtures_root):
    """Build the committed offline Draft 2020-12 validator for behavior vectors."""
    from jsonschema import Draft202012Validator, RefResolver

    schema_path = fixtures_root / "schema" / "vector.json"
    repo_root = fixtures_root.resolve().parents[1]
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    store = {}
    for path in sorted((fixtures_root / "schema").glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data.get("$id"), str):
            store[data["$id"]] = data
    for path in sorted((repo_root / "spec" / "revisions" / "2026-08" / "schema").glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data.get("$id"), str):
            store[data["$id"]] = data
    Draft202012Validator.check_schema(schema)
    return Draft202012Validator(
        schema,
        resolver=RefResolver(base_uri=schema["$id"], referrer=schema, store=store),
    )


def _walk_observational_shortcuts(value, path="$"):
    """Yield exact self-report keys/magic carrier strings with their JSON paths."""
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}"
            if key in OBSERVATIONAL_BANNED_KEYS:
                yield child_path
            yield from _walk_observational_shortcuts(child, child_path)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from _walk_observational_shortcuts(child, f"{path}[{index}]")
    elif isinstance(value, str) and OBSERVATIONAL_MAGIC_RE.match(value):
        yield path


def _check_behavior_observation_ownership(data, label, errors):
    """Check ownership, independently of a SUT event/payload oracle."""
    expect = data.get("expect") if isinstance(data.get("expect"), dict) else {}
    observations = expect.get("observations")
    if not isinstance(observations, list) or not observations:
        errors.append(f"{label}: target behavior carrier не имеет expect.observations[]")
        return
    kinds = [row.get("kind") for row in observations if isinstance(row, dict)]
    if len(kinds) != len(observations) or any(kind not in OBSERVATION_KINDS for kind in kinds):
        errors.append(f"{label}: observation kind вне закрытого registry")
    probe_ids = [row.get("probe_id") for row in observations if isinstance(row, dict)]
    if len(set(probe_ids)) != len(probe_ids):
        errors.append(f"{label}: probe_id в observations должны быть уникальны")
    for shortcut in _walk_observational_shortcuts(data):
        errors.append(f"{label}: запрещён self-report/magic shortcut {shortcut}")
    for index, event in enumerate(expect.get("events", [])):
        if isinstance(event, dict) and (
            event.get("kind") in OBSERVATION_KINDS
            or isinstance(event.get("observation"), dict)
            or (isinstance(event.get("payload"), dict) and "observation" in event["payload"])
        ):
            errors.append(f"{label}: harness observation подменено SUT event expect.events[{index}]")


def _observational_carrier_errors(fixtures_root, validator=None):
    errors = []
    seen_behavior = set()
    seen_artifact = set()
    validator = validator or _fixture_vector_validator(fixtures_root)
    for path in sorted((fixtures_root / "vectors").rglob("*.json")):
        data = load_json(path, errors)
        if not isinstance(data, dict):
            continue
        target = set(data.get("spec_rule_ids", [])) & OBSERVATIONAL_BEHAVIOR_RULES
        if not target:
            continue
        seen_behavior.update(target)
        schema_errors = list(validator.iter_errors(data))
        if schema_errors:
            errors.append(f"{path}: behavior vector невалиден против schema/vector.json ({schema_errors[0].message})")
        _check_behavior_observation_ownership(data, path, errors)
    for path in sorted((fixtures_root / "static").rglob("*.json")):
        data = load_json(path, errors)
        if not isinstance(data, dict):
            continue
        target = set(data.get("spec_rule_ids", [])) & OBSERVATIONAL_ARTIFACT_RULES
        if target:
            seen_artifact.update(target)
            subject = data.get("subject") if isinstance(data.get("subject"), dict) else {}
            expected_kinds = {OBSERVATIONAL_ARTIFACT_KINDS[rule] for rule in target}
            if len(target) != 1 or {subject.get("kind")} != expected_kinds or not isinstance(subject.get("inline"), str):
                errors.append(f"{path}: artifact observation должен быть single-rule raw candidate ожидаемого kind")
            for shortcut in _walk_observational_shortcuts(data):
                errors.append(f"{path}: запрещён self-report/magic shortcut {shortcut}")
    missing_behavior = sorted(OBSERVATIONAL_BEHAVIOR_RULES - seen_behavior)
    missing_artifact = sorted(OBSERVATIONAL_ARTIFACT_RULES - seen_artifact)
    if missing_behavior:
        errors.append("observational target behavior rules отсутствуют: " + ", ".join(missing_behavior))
    if missing_artifact:
        errors.append("observational target artifact rules отсутствуют: " + ", ".join(missing_artifact))
    return errors


def run_observational_carriers_check(fixtures_root):
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    try:
        validator = _fixture_vector_validator(fixtures_root)
    except (ImportError, Exception) as exc:
        return report([f"observational carriers: offline validator недоступен ({exc})"])
    return report(_observational_carrier_errors(fixtures_root, validator), "observational carriers: exact 51")


def run_observational_carriers_selftest(fixtures_root):
    """Prove schema closure and ownership rejection for every observation kind."""
    if not fixtures_root.is_dir():
        print(f"Ошибка: {fixtures_root} не является директорией", file=sys.stderr)
        return 2
    try:
        validator = _fixture_vector_validator(fixtures_root)
        tool = json.loads((fixtures_root / "common" / "tool-read.json").read_text(encoding="utf-8"))
    except Exception as exc:
        return report([f"observational selftest: offline validator недоступен ({exc})"])
    observations = {
        "tool_boundary": {
            "kind": "tool_boundary", "probe_id": "tool-spy", "tool": "invoice_read",
            "phase": "commit", "calls": 1, "route": "isolated", "actor_ids": ["agent-1"],
            "credential_ids": [],
        },
        "journal_projection": {
            "kind": "journal_projection", "probe_id": "journal-fold", "state": "completed",
            "source_event_ids": ["event-1"], "emitted_event_kinds": [], "source_unchanged": True,
        },
        "audit_sink": {
            "kind": "audit_sink", "probe_id": "otel-sink", "record_count": 1,
            "attribute_names": ["action_id"], "baggage_count": 0, "baggage_bytes": 0,
        },
        "task_binding": {
            "kind": "task_binding", "probe_id": "task-channel", "task_id": "task-1",
            "binding_id": "binding-1", "state": "input_required",
            "input_required_operations": ["tasks/result"],
        },
        "credential_boundary": {
            "kind": "credential_boundary", "probe_id": "resource-spy", "resource": "ledger",
            "principal_ids": ["user-1"], "forwarded_credential_ids": [],
        },
        "confirmation_boundary": {
            "kind": "confirmation_boundary", "probe_id": "confirmation-spy",
            "proposal_count": 1, "token_issue_count": 1, "token_consume_count": 1,
            "decision_event_count": 1,
        },
    }
    errors = []
    for kind, observation in observations.items():
        base = {
            "id": "observation-000", "title": "Контрольная observation probe",
            "vector_class": "behavior", "spec_rev": "2026-08", "spec_rule_ids": ["TLP-11"],
            "conformance_level": "L1", "given": {"tools": [tool]},
            "script": [{"user_input": "probe"}],
            "expect": {"events": [], "final_state": "completed", "observations": [observation]},
        }
        positive_errors = list(validator.iter_errors(base))
        negative = deepcopy(base)
        negative["expect"]["observations"][0]["unexpected"] = True
        negative_errors = list(validator.iter_errors(negative))
        ownership_errors = []
        _check_behavior_observation_ownership(base, kind, ownership_errors)
        mutant = deepcopy(base)
        moved = mutant["expect"].pop("observations")[0]
        mutant["expect"]["events"] = [{"kind": "action_progress", "payload": {"observation": moved}}]
        mutant_schema_errors = list(validator.iter_errors(mutant))
        mutant_ownership_errors = []
        _check_behavior_observation_ownership(mutant, kind, mutant_ownership_errors)
        if positive_errors or not negative_errors or ownership_errors or mutant_schema_errors or not mutant_ownership_errors:
            errors.append(
                f"observational selftest {kind}: schema-positive={len(positive_errors)}, "
                f"schema-negative={len(negative_errors)}, ownership-positive={len(ownership_errors)}, "
                f"mutant-schema={len(mutant_schema_errors)}, mutant-reject={len(mutant_ownership_errors)}"
            )
        else:
            print(f"observation {kind}: schema-positive=0, schema-negative>0, self-report-reject>0")
    return report(errors, "observational ownership: 6 kinds, positive + schema-invalid + self-report reject")


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
        "  python3 spec_lint.py --fixtures-check <fixtures_root>\n"
        "  python3 spec_lint.py --namespace-inventory <revision_dir>\n"
        "  python3 spec_lint.py --namespace-inventory-selftest\n"
        "  python3 spec_lint.py --actionable-description-selftest <revision_dir>\n"
        "  python3 spec_lint.py --schema-values-selftest <revision_dir>\n"
        "  python3 spec_lint.py --driver-state-selftest <fixtures_root>\n"
        "  python3 spec_lint.py --observational-carriers-check|--observational-carriers-selftest <fixtures_root>\n"
        "  python3 spec_lint.py --gate-b-remediation-write|--gate-b-remediation-check <fixtures_root>",
        file=sys.stderr,
    )
    return 2


def main():
    args = sys.argv[1:]

    if args == ["--namespace-inventory-selftest"]:
        return namespace_inventory_selftest()

    if "--actionable-description-selftest" in args:
        try:
            target = Path(args[args.index("--actionable-description-selftest") + 1])
        except IndexError:
            return usage()
        return run_actionable_description_selftest(target)

    if "--schema-values-selftest" in args:
        try:
            target = Path(args[args.index("--schema-values-selftest") + 1])
        except IndexError:
            return usage()
        return run_schema_values_selftest(target)

    if "--driver-state-selftest" in args:
        try:
            target = Path(args[args.index("--driver-state-selftest") + 1])
        except IndexError:
            return usage()
        return run_driver_state_selftest(target)

    if "--observational-carriers-check" in args or "--observational-carriers-selftest" in args:
        mode = "--observational-carriers-check" if "--observational-carriers-check" in args else "--observational-carriers-selftest"
        try:
            target = Path(args[args.index(mode) + 1])
        except IndexError:
            return usage()
        return run_observational_carriers_check(target) if mode.endswith("check") else run_observational_carriers_selftest(target)

    if "--gate-b-remediation-write" in args or "--gate-b-remediation-check" in args:
        mode = "--gate-b-remediation-write" if "--gate-b-remediation-write" in args else "--gate-b-remediation-check"
        try:
            target = Path(args[args.index(mode) + 1])
        except IndexError:
            return usage()
        return run_gate_b_remediation_write(target) if mode.endswith("write") else run_gate_b_remediation_check(target)

    if "--namespace-inventory" in args:
        try:
            target = Path(args[args.index("--namespace-inventory") + 1])
        except IndexError:
            return usage()
        return run_namespace_inventory(target)

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
        definitions = check_open_enum_contracts(revision_dir, errors)
        check_lifecycle_event_contract(revision_dir, errors, definitions)
        check_error_code_registry(revision_dir, errors)
    if profile["description_no_norm"]:
        check_description_no_norm(revision_dir, profile, errors)
    if profile.get("actionable_description"):
        check_actionable_description_profile(revision_dir, profile, errors)
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
