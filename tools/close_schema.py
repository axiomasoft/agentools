#!/usr/bin/env python3
"""close_schema.py — генератор ЗАКРЫТЫХ схем авторинга ревизии `2026-08`.

Использование:
    python3 tools/close_schema.py <revision_dir> --write     # schema/*.json → schema/authoring/*.json
    python3 tools/close_schema.py <revision_dir> --check     # непустой diff → exit 1
    python3 tools/close_schema.py <schema.json> --stdout     # один файл в stdout
    python3 tools/close_schema.py --selftest                 # встроенные случаи

Почему генератор, а не второй рукописный артефакт: `DR-01` §J9 требует прогонять
фикстуры и примеры «против ЗАКРЫТОГО ВАРИАНТА ТЕХ ЖЕ СХЕМ». Пара «открытая +
закрытая», написанная руками, разъезжается ровно тем дрейфом, который §J9 и
создавался ловить, поэтому `schema/authoring/**` имеет единственного автора — этот
скрипт (D42), а ручная правка закрытых схем запрещена.

Механика закрытия — дословно Список B (`DR-02` §3): `unevaluatedProperties: false`
на ВНЕШНЕМ уровне композиции плюс `additionalProperties: false` в ЛИСТОВЫХ
подсхемах без `allOf`/`$ref`. Всё остальное остаётся открытым.

Без сетевых обращений, stdlib-only (D14).
"""
import json
import re
import sys
from pathlib import Path

# Ключевые слова, делающие узел КОМПОЗИЦИЕЙ: они приносят в узел свойства извне
# (in-place applicators 2020-12), поэтому закрывать такой узел можно только
# `unevaluatedProperties`, который видит аннотации всех применённых подсхем.
COMPOSITION_KEYWORDS = ("allOf", "anyOf", "oneOf", "$ref")

# Признаки объектной схемы. `type: object` может отсутствовать (схемы ревизии
# опираются на `properties`), поэтому смотрим на оба носителя формы.
OBJECT_MARKERS = ("properties", "patternProperties")

# Позиции, в которые генератор спускается у ЛИСТОВОГО объекта. Ветви композиции
# (`allOf`/`anyOf`/`oneOf`) в этот список НЕ входят намеренно — см. `_close_node`.
CHILD_SCHEMA_KEYS = ("items", "contains", "propertyNames", "additionalProperties")
CHILD_MAP_KEYS = ("properties", "patternProperties", "dependentSchemas")

# `$id` опубликованной схемы ревизии; закрытая живёт под тем же корнем + `authoring/`
# (Implementation Rules P3.1: база `authoring/` + имя файла).
SCHEMA_ID_RE = re.compile(r"^(https://agentools\.dev/schema/[^/]+/)(?:authoring/)?([^/]+)$")


class CloseSchemaError(ValueError):
    """Отказ, названный своей конструкцией: генератор НЕ подгоняет эвристику под
    схему, которую не умеет закрыть механически (Code Guidance P3.1 — такой случай
    эскалируется как граница Списка B, а не чинится по месту)."""


def _authoring_id(original, filename):
    """`$id` закрытой схемы = база опубликованной + `authoring/` + имя файла.
    `$id` вне базы ревизии (например схема пробы bakeoff) не переписывается: у
    закрытого варианта чужого артефакта нет адреса в пространстве ревизии."""
    if not isinstance(original, str):
        return original
    match = SCHEMA_ID_RE.match(original)
    if not match:
        return original
    return f"{match.group(1)}authoring/{filename}"


def _is_schema(node):
    return isinstance(node, dict)


def _has_composition(node):
    return any(key in node for key in COMPOSITION_KEYWORDS)


def _is_object_schema(node):
    if node.get("type") == "object":
        return True
    return any(key in node for key in OBJECT_MARKERS)


def _already_closed(node):
    """Явно выставленное автором закрытие/открытие не перетирается: `additionalProperties`
    со СХЕМОЙ — это намеренно открытая map (Список A разрешает), и превращать её в
    `false` значило бы менять смысл схемы, а не закрывать её."""
    return "additionalProperties" in node or "unevaluatedProperties" in node


def _resolve_internal_ref(ref, root):
    """Резолвит только внутрифайловый указатель. Сетевой `$ref` запрещён обоими
    списками (`DR-02` §2–§3), поэтому его отсутствие здесь — не пробел, а норма."""
    if not isinstance(ref, str) or not ref.startswith("#/"):
        return None
    target = root
    for part in ref[2:].split("/"):
        if not isinstance(target, dict):
            return None
        target = target.get(part.replace("~1", "/").replace("~0", "~"))
    return target


def _describes_object(node, root, seen=None):
    """Описывает ли узел ОБЪЕКТ. `unevaluatedProperties` имеет смысл только для
    объектов: композиция `oneOf` из `const`-веток — это открытое ПЕРЕЧИСЛЕНИЕ
    значения (`DR-01` §E2), и закрывать её нечем и не от чего."""
    if not isinstance(node, dict):
        return False
    if _is_object_schema(node):
        return True
    seen = seen if seen is not None else set()
    ref = node.get("$ref")
    if isinstance(ref, str) and ref not in seen:
        seen.add(ref)
        if _describes_object(_resolve_internal_ref(ref, root), root, seen):
            return True
    for keyword in ("allOf", "anyOf", "oneOf"):
        for branch in node.get(keyword, []) or []:
            if _describes_object(branch, root, seen):
                return True
    return False


def _close_node(node, path, closed_marks, root):
    """Закрывает ОДИН узел и, если он листовой объект, спускается в его подсхемы.

    Почему не спускаемся под композицию: свойства, объявленные в одной ветви
    `allOf`, могут дополняться другой ветвью, и `additionalProperties: false`
    внутри ветви отвергал бы документ, валидный по композиции целиком. Это ровно
    антипаттерн `DR-01` §E5 («запрещён во вложенных и переиспользуемых подсхемах»),
    поэтому внешний уровень закрывается `unevaluatedProperties`, а внутренности
    остаются нетронутыми.
    """
    if not _is_schema(node):
        return

    if _has_composition(node):
        if _describes_object(node, root) and not _already_closed(node):
            node["unevaluatedProperties"] = False
            closed_marks.append((path or "<root>", "unevaluatedProperties"))
        return

    if _is_object_schema(node):
        if not _already_closed(node):
            node["additionalProperties"] = False
            closed_marks.append((path or "<root>", "additionalProperties"))
        for key in CHILD_MAP_KEYS:
            container = node.get(key)
            if isinstance(container, dict):
                for name, child in container.items():
                    _close_node(child, f"{path}/{key}/{name}", closed_marks, root)
        for key in CHILD_SCHEMA_KEYS:
            child = node.get(key)
            if isinstance(child, dict):
                _close_node(child, f"{path}/{key}", closed_marks, root)
        return

    for key in CHILD_SCHEMA_KEYS:
        child = node.get(key)
        if isinstance(child, dict):
            _close_node(child, f"{path}/{key}", closed_marks, root)


def close_schema(data, filename):
    """Возвращает закрытый вариант схемы. `$defs` не трогается НИКОГДА: это по
    построению место переиспользуемых подсхем, а именно их `DR-01` §E5 запрещает
    закрывать — закрытие переиспользуемого узла отвергло бы документ у любого
    потребителя композиции, в которую он входит."""
    if not isinstance(data, dict):
        raise CloseSchemaError("корень схемы должен быть объектом")

    closed = json.loads(json.dumps(data))
    closed_marks = []
    _close_node(closed, "", closed_marks, closed)

    if not closed_marks and not _already_closed(closed):
        raise CloseSchemaError(
            "корень не является ни объектной схемой, ни композицией — механического "
            "закрытия для этой конструкции не существует (граница Списка B, DR-02 §3)"
        )

    if "$id" in closed:
        closed["$id"] = _authoring_id(closed["$id"], filename)
    return closed


def render(data):
    """Детерминированный вид: сортировка ключей, отступ 2, LF, ровно один
    завершающий перевод строки. Свежесть производного артефакта проверяется
    побайтным diff'ом (`--check`), поэтому вид обязан быть функцией только входа."""
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _revision_pairs(revision_dir):
    source_dir = revision_dir / "schema"
    target_dir = revision_dir / "schema" / "authoring"
    if not source_dir.is_dir():
        raise CloseSchemaError(f"{source_dir} не существует")
    for schema_file in sorted(source_dir.glob("*.json")):
        yield schema_file, target_dir / schema_file.name


def run_revision(revision_dir, write, errors):
    """`--write` пишет закрытые схемы, `--check` сверяет их побайтно с тем, что
    сгенерировалось бы сейчас: непустой diff = производный артефакт протух."""
    target_dir = revision_dir / "schema" / "authoring"
    produced = 0
    for source, target in _revision_pairs(revision_dir):
        try:
            data = json.loads(source.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"{source}: невалидный JSON ({exc})")
            continue
        try:
            text = render(close_schema(data, source.name))
        except CloseSchemaError as exc:
            errors.append(f"{source}: {exc}")
            continue
        produced += 1
        if write:
            target_dir.mkdir(parents=True, exist_ok=True)
            target.write_text(text, encoding="utf-8")
        else:
            if not target.is_file():
                errors.append(f"{target}: закрытая схема отсутствует (запустите --write)")
            elif target.read_text(encoding="utf-8") != text:
                errors.append(f"{target}: расходится с генератором (непустой diff)")
    return produced


SELFTEST_CASES = [
    (
        "композиция на внешнем уровне закрывается unevaluatedProperties",
        {"allOf": [{"type": "object", "properties": {"id": {"type": "string"}}}]},
        {"allOf": [{"type": "object", "properties": {"id": {"type": "string"}}}],
         "unevaluatedProperties": False},
    ),
    (
        "листовой объект закрывается additionalProperties",
        {"type": "object", "properties": {"a": {"type": "string"}}},
        {"type": "object", "properties": {"a": {"type": "string"}},
         "additionalProperties": False},
    ),
    (
        "вложенный листовой объект закрывается тоже",
        {"type": "object", "properties": {"meta": {"type": "object", "properties": {"k": {"type": "string"}}}}},
        {"type": "object", "additionalProperties": False,
         "properties": {"meta": {"type": "object", "additionalProperties": False,
                                 "properties": {"k": {"type": "string"}}}}},
    ),
    (
        "$defs и ветви композиции не трогаются (E5: переиспользуемые подсхемы)",
        {"$ref": "#/$defs/Named",
         "$defs": {"Named": {"allOf": [{"type": "object", "properties": {"id": {"type": "string"}}}]}}},
        {"$ref": "#/$defs/Named", "unevaluatedProperties": False,
         "$defs": {"Named": {"allOf": [{"type": "object", "properties": {"id": {"type": "string"}}}]}}},
    ),
    (
        "открытая map с additionalProperties-схемой не перетирается",
        {"type": "object", "properties": {}, "additionalProperties": {"type": "string"}},
        {"type": "object", "properties": {}, "additionalProperties": {"type": "string"}},
    ),
    (
        "открытое перечисление значения (oneOf из const) не закрывается",
        {"type": "object", "properties": {"kind": {"oneOf": [{"const": "llm"}, {"const": "tool"}]}}},
        {"type": "object", "additionalProperties": False,
         "properties": {"kind": {"oneOf": [{"const": "llm"}, {"const": "tool"}]}}},
    ),
    (
        "$id переписывается на базу authoring/",
        {"$id": "https://agentools.dev/schema/2026-08/tool-descriptor.json",
         "type": "object", "properties": {"a": {"type": "string"}}},
        {"$id": "https://agentools.dev/schema/2026-08/authoring/tool-descriptor.json",
         "type": "object", "properties": {"a": {"type": "string"}},
         "additionalProperties": False},
    ),
]

SELFTEST_UNCLOSABLE = {"type": "array", "items": {"type": "string"}}


def selftest():
    """Встроенные случаи: каждая ветвь алгоритма закрытия плюс идемпотентность и
    отказ на конструкции, которую механически закрыть нельзя."""
    failures = []
    for title, source, expected in SELFTEST_CASES:
        filename = Path(source.get("$id", "case.json")).name
        try:
            got = close_schema(source, filename)
        except CloseSchemaError as exc:
            failures.append(f"{title}: неожиданный отказ ({exc})")
            continue
        if got != expected:
            failures.append(f"{title}: получено {render(got).strip()}")
            continue
        again = close_schema(got, filename)
        if render(again) != render(got):
            failures.append(f"{title}: закрытие не идемпотентно")

    try:
        close_schema(SELFTEST_UNCLOSABLE, "case.json")
    except CloseSchemaError:
        pass
    else:
        failures.append("незакрываемая конструкция (корень-массив) не отвергнута")

    if failures:
        print(f"close_schema --selftest: {len(failures)} провал(ов):", file=sys.stderr)
        for failure in failures:
            print(f"  - {failure}", file=sys.stderr)
        return 1
    print(f"close_schema --selftest: OK ({len(SELFTEST_CASES) + 1} случаев)")
    return 0


def usage():
    print(__doc__.split("\n\n")[1], file=sys.stderr)
    return 2


def main():
    args = sys.argv[1:]
    if "--selftest" in args:
        return selftest()

    write = "--write" in args
    check = "--check" in args
    to_stdout = "--stdout" in args
    positional = [a for a in args if not a.startswith("--")]
    if len(positional) != 1 or sum((write, check, to_stdout)) != 1:
        return usage()

    target = Path(positional[0])
    errors = []

    if target.is_dir():
        if to_stdout:
            print("Ошибка: --stdout работает с одним файлом схемы", file=sys.stderr)
            return 2
        try:
            produced = run_revision(target, write, errors)
        except CloseSchemaError as exc:
            print(f"Ошибка: {exc}", file=sys.stderr)
            return 2
    elif target.is_file():
        try:
            data = json.loads(target.read_text(encoding="utf-8"))
            text = render(close_schema(data, target.name))
        except json.JSONDecodeError as exc:
            print(f"{target}: невалидный JSON ({exc})", file=sys.stderr)
            return 1
        except CloseSchemaError as exc:
            print(f"{target}: {exc}", file=sys.stderr)
            return 1
        produced = 1
        if to_stdout:
            sys.stdout.write(text)
        else:
            authoring = target.parent / "authoring" / target.name
            if write:
                authoring.parent.mkdir(parents=True, exist_ok=True)
                authoring.write_text(text, encoding="utf-8")
            elif not authoring.is_file():
                errors.append(f"{authoring}: закрытая схема отсутствует (запустите --write)")
            elif authoring.read_text(encoding="utf-8") != text:
                errors.append(f"{authoring}: расходится с генератором (непустой diff)")
    else:
        print(f"Ошибка: {target} не найден", file=sys.stderr)
        return 2

    if errors:
        print(f"close_schema: {len(errors)} ошибок(a):", file=sys.stderr)
        for error in errors:
            print(f"  - {error}", file=sys.stderr)
        return 1
    if not to_stdout:
        action = "записано" if write else "сверено"
        print(f"close_schema: OK ({action} схем: {produced})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
