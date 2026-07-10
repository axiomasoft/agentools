#!/usr/bin/env python3
"""spec_lint.py — проверка целостности ревизии спецификации AgenTools.

Использование:
    python3 tools/spec_lint.py <revision_dir> [--allow-missing]

Проверки:
  1. Каждый schema/*.json парсится и содержит корректные $schema/$id.
  2. Каждый нормативный NN_*.md существует (пропускается с --allow-missing).
  3. Markdown-ссылки на файлы внутри ревизии разрешаются.
  4. Блоки ```json в докax парсятся как валидный JSON.
  5. Если json-блок начинается с комментарием "// example: <schema-file>" —
     соответствующий файл схемы должен существовать.

Без сетевых обращений, stdlib-only. Ошибки собираются списком и печатаются
разом; exit 1 при наличии хотя бы одной ошибки, иначе exit 0.
"""
import json
import re
import sys
from pathlib import Path

# Список обязательных нормативных документов ревизии. Пополняется по мере
# развития спеки (например, 07_conformance-adapter.md добавляется в фазе P1).
REQUIRED_DOCS = [
    "00_overview.md",
    "01_tool-contract-profile.md",
    "02_action-lifecycle.md",
    "03_confirmation-protocol.md",
    "04_scenario-schema.md",
    "05_audit-events.md",
    "06_idempotency-errors.md",
]

EXPECTED_SCHEMA_DIALECT = "https://json-schema.org/draft/2020-12/schema"

MD_LINK_RE = re.compile(r"\[[^\]]*\]\(([^)]+)\)")
JSON_BLOCK_RE = re.compile(r"```json\n(.*?)\n```", re.DOTALL)
EXAMPLE_COMMENT_RE = re.compile(r"^//\s*example:\s*(\S+)\s*$")

# P0.9: fixtures/ проверяются вместе с ревизией спеки (см. Validation item'а). Формат
# spec_ref-маркера — общий для всех нормативных доков 01-06 (TCP-1, LC-T13, CP-7, SC-15,
# AU-11, IE-6).
SPEC_REF_RE = re.compile(r"^(TCP|LC|CP|SC|AU|IE)-T?[0-9]+$")
SESSION_STATES = {
    "CREATED", "RUNNING", "WAITING_INPUT", "WAITING_APPROVAL", "EXECUTING_TOOL",
    "COMPLETED", "FAILED_RETRYABLE", "FAILED_TERMINAL", "CANCELLED", "EXPIRED",
}
FIXTURE_REQUIRED_KEYS = ["id", "title", "spec_refs", "conformance_level", "given", "script", "expect"]
SCRIPT_STEP_KEYS = {"user_input", "llm_stub", "clarification_answer", "approval", "tick_seconds"}
MIN_VECTORS = 15


def check_required_docs(revision_dir: Path, allow_missing: bool, errors: list) -> None:
    if allow_missing:
        return
    for name in REQUIRED_DOCS:
        if not (revision_dir / name).is_file():
            errors.append(f"отсутствует обязательный документ: {name}")


def check_schemas(revision_dir: Path, errors: list) -> None:
    schema_dir = revision_dir / "schema"
    if not schema_dir.is_dir():
        return
    revision_name = revision_dir.name
    for schema_file in sorted(schema_dir.glob("*.json")):
        try:
            data = json.loads(schema_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            errors.append(f"{schema_file}: невалидный JSON ({e})")
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
        expected_id = f"https://agentools.dev/schema/{revision_name}/{schema_file.name}"
        actual_id = data.get("$id")
        if actual_id != expected_id:
            errors.append(
                f"{schema_file}: $id должен быть '{expected_id}', получено {actual_id!r}"
            )


def check_markdown_links(revision_dir: Path, errors: list) -> None:
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


def check_json_blocks(revision_dir: Path, errors: list) -> None:
    schema_dir = revision_dir / "schema"
    for md_file in sorted(revision_dir.glob("*.md")):
        text = md_file.read_text(encoding="utf-8")
        for i, match in enumerate(JSON_BLOCK_RE.finditer(text), start=1):
            block = match.group(1)
            lines = block.split("\n", 1)
            first_line = lines[0]
            json_text = block
            example_match = EXAMPLE_COMMENT_RE.match(first_line.strip())
            if example_match:
                schema_name = example_match.group(1)
                schema_path = schema_dir / schema_name
                if not schema_path.is_file():
                    errors.append(
                        f"{md_file}: json-блок #{i} ссылается на несуществующую "
                        f"схему {schema_name!r}"
                    )
                json_text = lines[1] if len(lines) > 1 else ""
            try:
                json.loads(json_text)
            except json.JSONDecodeError as e:
                errors.append(f"{md_file}: json-блок #{i} невалиден ({e})")


def _spec_ref_exists(ref: str, docs_corpus: str) -> bool:
    """Маркер (напр. 'LC-T13') MUST существовать в текстах доков 01-06 как точное
    вхождение (границы — не буква/цифра/дефис), а не как подстрока внутри другого
    маркера (иначе 'LC-1' ложно совпал бы внутри 'LC-15')."""
    pattern = re.compile(r"(?<![A-Za-z0-9-])" + re.escape(ref) + r"(?![A-Za-z0-9-])")
    return pattern.search(docs_corpus) is not None


def check_fixtures(revision_dir: Path, errors: list) -> None:
    """P0.9: fixtures/ — языконезависимые тест-векторы (не часть revision_dir, лежат
    на уровне репозитория). Проверки структурные (без полной JSON-Schema-валидации —
    D14: та приходит в P1); цель — держать 15 векторов и golden-файлы честными
    относительно нормативных доков и формата fixture.json."""
    repo_root = revision_dir.resolve().parents[2]
    fixtures_dir = repo_root / "fixtures"
    if not fixtures_dir.is_dir():
        errors.append("отсутствует директория fixtures/ (P0.9)")
        return

    fixture_schema_path = fixtures_dir / "schema" / "fixture.json"
    if not fixture_schema_path.is_file():
        errors.append("отсутствует fixtures/schema/fixture.json (P0.9)")
    else:
        try:
            json.loads(fixture_schema_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            errors.append(f"{fixture_schema_path}: невалидный JSON ({e})")

    docs_corpus = "\n".join(
        (revision_dir / name).read_text(encoding="utf-8")
        for name in REQUIRED_DOCS
        if (revision_dir / name).is_file()
    )

    vectors_dir = fixtures_dir / "vectors"
    vector_files = sorted(vectors_dir.glob("**/*.json")) if vectors_dir.is_dir() else []
    if len(vector_files) < MIN_VECTORS:
        errors.append(
            f"fixtures/vectors: найдено {len(vector_files)} векторов, требуется минимум {MIN_VECTORS} (P0.9)"
        )

    seen_ids: dict = {}
    for vf in vector_files:
        try:
            data = json.loads(vf.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            errors.append(f"{vf}: невалидный JSON ({e})")
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
                if not isinstance(ref, str) or not SPEC_REF_RE.match(ref):
                    errors.append(f"{vf}: spec_ref '{ref}' не соответствует формату маркера")
                elif not _spec_ref_exists(ref, docs_corpus):
                    errors.append(f"{vf}: spec_ref '{ref}' не найден ни в одном из доков 01-06")

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
                errors.append(f"{vf}: expect.final_state отсутствует или не входит в состояния FSM (02/LC-1)")

    check_golden_pairs(fixtures_dir / "golden" / "jcs", "vector-*.input.json", errors, min_pairs=3)
    check_golden_pairs(fixtures_dir / "golden" / "idempotency", "key-*.input.json", errors, min_pairs=1)


def check_golden_pairs(golden_dir: Path, input_glob: str, errors: list, min_pairs: int) -> None:
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
        except json.JSONDecodeError as e:
            errors.append(f"{input_file}: невалидный JSON ({e})")


def main() -> int:
    args = sys.argv[1:]
    allow_missing = "--allow-missing" in args
    positional = [a for a in args if a != "--allow-missing"]
    if len(positional) != 1:
        print(
            "Использование: python3 spec_lint.py <revision_dir> [--allow-missing]",
            file=sys.stderr,
        )
        return 2
    revision_dir = Path(positional[0])
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2

    errors: list = []
    check_required_docs(revision_dir, allow_missing, errors)
    check_schemas(revision_dir, errors)
    check_markdown_links(revision_dir, errors)
    check_json_blocks(revision_dir, errors)
    check_fixtures(revision_dir, errors)

    if errors:
        print(f"spec_lint: {len(errors)} ошибок(a):", file=sys.stderr)
        for e in errors:
            print(f"  - {e}", file=sys.stderr)
        return 1

    print("spec_lint: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
