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

    if errors:
        print(f"spec_lint: {len(errors)} ошибок(a):", file=sys.stderr)
        for e in errors:
            print(f"  - {e}", file=sys.stderr)
        return 1

    print("spec_lint: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
