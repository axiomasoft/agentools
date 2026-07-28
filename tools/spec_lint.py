#!/usr/bin/env python3
"""spec_lint.py — проверка целостности ревизии спецификации AgenTools.

Использование:
    python3 tools/spec_lint.py <revision_dir> [--allow-missing]
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

Режим `--fixtures-check` (P4.1, `research/03-p4-vector-corpus-2026-08.md` §3) — структурная
сверка корпуса `fixtures/2026-08/**` со схемой класса своего вектора (`behavior`/`artifact`/
`harness`), проверка `spec_rev`, потолка `spec_rule_ids` (8, анти-вырождение) и соответствия
каталога классу. Полная JSON-Schema-валидация вне периметра (D14, stdlib-only) — приходит
эмпирически (`bakeoff/`) и с раннером фазы P6; двунаправленная traceability «правило ↔
вектор» — отдельный гейт фазы P4.5, этим режимом не подменяется.

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
        # Порог поднимает фаза P4: на момент P3 корпуса ещё нет, и «зелёный на
        # пустом» здесь не самообман, а честное отсутствие предмета.
        "min_vectors": 0,
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
        # D53 (P4.1): корпус 2026-08 несёт ТРИ схемы формата (по классу вектора),
        # а не единый fixture.json — структурная сверка и золотой минимум живут в
        # `--fixtures-check` (check_fixtures_format) и в точечной Validation P4.1/
        # P4.3, а не в этом общем проходе. Пока `min_vectors == 0` (гейт P4.5 ещё
        # не поднял порог), корпус официально «ещё не populated» той же логикой,
        # что и отсутствующий каталог веткой выше — общий проход фикстуры не судит.
        if min_vectors == 0:
            return
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


def report(errors, suffix=None):
    if errors:
        print(f"spec_lint: {len(errors)} ошибок(a):", file=sys.stderr)
        for error in errors:
            print(f"  - {error}", file=sys.stderr)
        return 1
    print("spec_lint: OK" if suffix is None else f"spec_lint: OK ({suffix})")
    return 0


def usage():
    print(
        "Использование:\n"
        "  python3 spec_lint.py <revision_dir> [--allow-missing]\n"
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

    errors = []
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

    return report(errors)


if __name__ == "__main__":
    sys.exit(main())
