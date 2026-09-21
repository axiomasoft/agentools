#!/usr/bin/env python3
"""rules_index.py — детерминированный генератор индекса правил ревизии `2026-08`.

Использование:
    python3 tools/rules_index.py --write <revision_dir>
    python3 tools/rules_index.py --check <revision_dir>

Индекс `rules.json` — производный артефакт (`research/00-user-intent.md` §3 п.2):
из markdown ревизии по одному проходу вытаскивается запись на каждое нормативное
правило (`{rule_id, doc, normative_word, level, not_testable, text_digest}`).
Ручная правка `rules.json` запрещена — `--check` ловит расхождение (пустой diff —
единственный признак того, что файл действительно порождён этим генератором, а не
подправлен вручную «на глаз»).

Грамматика строки правила и таблица «маркер → документ» — ОДИН носитель,
`tools/spec_lint.py` (`RULE_LINE_RE_2026_08`/`RULE_PREFIXES_2026_08`): этот генератор
их ИМПОРТИРУЕТ, а не копирует второй раз — второй носитель одной грамматики есть
ровно тот anti-regression-дефект (правило в двух формах сразу), который вся
ревизия обязана не воспроизводить (`research/00-user-intent.md` §4 №3).

`text_digest` — `sha256hex` НОРМАЛИЗОВАННОГО нормативного абзаца: строка правила и
все последующие НЕпустые строки до первой пустой, пробельные последовательности
(включая перевод строки внутри абзаца) схлопнуты в один пробел. Абзац, а не одна
строка — единица дайджеста, потому что список условий правила (нумерованный список
после ведущего предложения) переносится на следующие строки и является частью
нормативного текста (`research/02-…-2026-08.md` §3).

Порядок записей детерминирован: сперва по документу (порядок `DOCS_2026_08` —
позиция в ревизии, не алфавит), затем по ЧИСЛОВОЙ части маркера (`int`, не
лексикографически — иначе `ALC-10` встал бы перед `ALC-2`).

Без сетевых обращений, stdlib-only (D14).
"""
import hashlib
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import spec_lint  # noqa: E402  (грамматика и таблица префиксов — единственный носитель)

RULE_NUMBER_RE = re.compile(r"-T?(?P<number>\d+)$")


def _rule_paragraphs(doc_text, candidate_re, rule_line_re):
    lines = doc_text.split("\n")
    for lineno, line in enumerate(lines, start=1):
        if not candidate_re.match(line):
            continue
        match = rule_line_re.match(line)
        if not match:
            continue
        paragraph = [line]
        j = lineno
        while j < len(lines) and lines[j].strip() != "":
            paragraph.append(lines[j])
            j += 1
        yield lineno, match, paragraph


def _text_digest(paragraph_lines):
    normalized = re.sub(r"\s+", " ", "\n".join(paragraph_lines)).strip()
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest()


def _sort_key(entry, docs):
    doc_index = docs.index(entry["doc"])
    number = int(RULE_NUMBER_RE.search(entry["rule_id"]).group("number"))
    return (doc_index, number)


def build_index(revision_dir):
    """Строит индекс в памяти — без побочных эффектов на диске (нужно и `--write`,
    и `--check`, различается только запись результата)."""
    rev = revision_dir.resolve().name
    docs = spec_lint.DOCS_2026_09 if rev == "2026-09" else spec_lint.DOCS_2026_08
    candidate_re = (
        spec_lint.RULE_CANDIDATE_RE_2026_09
        if rev == "2026-09"
        else spec_lint.RULE_CANDIDATE_RE_2026_08
    )
    rule_line_re = (
        spec_lint.RULE_LINE_RE_2026_09 if rev == "2026-09" else spec_lint.RULE_LINE_RE_2026_08
    )
    entries = []
    for name in docs:
        doc_path = revision_dir / name
        if not doc_path.is_file():
            continue
        text = doc_path.read_text(encoding="utf-8")
        for _lineno, match, paragraph in _rule_paragraphs(text, candidate_re, rule_line_re):
            entries.append(
                {
                    "rule_id": match.group("id"),
                    "doc": name,
                    "normative_word": match.group("word"),
                    "level": match.group("level"),
                    "not_testable": match.group("reason"),
                    "text_digest": _text_digest(paragraph),
                }
            )
    entries.sort(key=lambda entry: _sort_key(entry, docs))
    return {
        "spec_rev": revision_dir.resolve().name,
        "generator": "tools/rules_index.py",
        "rules": entries,
    }


def render(index):
    """Детерминированный формат: отступ 2, LF, РОВНО один завершающий перевод
    строки — этим и проверяется `--check` побайтно."""
    return json.dumps(index, indent=2, ensure_ascii=False) + "\n"


def usage():
    print(
        "Использование:\n"
        "  python3 tools/rules_index.py --write <revision_dir>\n"
        "  python3 tools/rules_index.py --check <revision_dir>",
        file=sys.stderr,
    )
    return 2


def main():
    args = sys.argv[1:]
    if "--write" in args:
        mode = "write"
        args = [a for a in args if a != "--write"]
    elif "--check" in args:
        mode = "check"
        args = [a for a in args if a != "--check"]
    else:
        return usage()
    if len(args) != 1:
        return usage()

    revision_dir = Path(args[0])
    if not revision_dir.is_dir():
        print(f"Ошибка: {revision_dir} не является директорией", file=sys.stderr)
        return 2

    index = build_index(revision_dir)
    rendered = render(index)
    out_path = revision_dir / "rules.json"

    if mode == "write":
        out_path.write_text(rendered, encoding="utf-8")
        print(f"rules_index: записано {len(index['rules'])} правил в {out_path}")
        return 0

    if not out_path.is_file():
        print(f"rules_index: отсутствует {out_path} — запусти --write сначала", file=sys.stderr)
        return 1
    current = out_path.read_text(encoding="utf-8")
    if current != rendered:
        print(f"rules_index: {out_path} расходится со сгенерированным (--check)", file=sys.stderr)
        return 1
    print(f"rules_index: OK, расхождений нет ({len(index['rules'])} правил)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
