#!/usr/bin/env python3
"""Единый носитель четырёх слоёв conformance-гейта.

Workflow намеренно вызывает только этот файл: локальная и CI-проверка должны иметь
один порядок и состав, иначе два одинаково названных «зелёных» результата разойдутся.
"""

from __future__ import annotations

import argparse
import json
import shlex
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
REVISION = "spec/revisions/2026-09"
FIXTURES = "fixtures/2026-09"
FROZEN_REVISION = "spec/revisions/2026-08"
FROZEN_FIXTURES = "fixtures/2026-08"
REFERENCE_ADAPTER = "php conformance/adapters/reference/adapter.php"


def command(*parts: str) -> list[str]:
    return list(parts)


def run_checks(layer: int, title: str, checks: list[tuple[str, list[str]]]) -> int:
    """Печатает состав слоя и останавливает гейт на первом наблюдаемом отказе."""
    completed = 0
    for label, args in checks:
        print(f"  → {label}: {' '.join(args)}", flush=True)
        try:
            result = subprocess.run(args, cwd=ROOT, check=False)
        except OSError as error:
            print(f"  ! {label}: {error}", file=sys.stderr)
            result = subprocess.CompletedProcess(args, 1)
        completed += 1
        if result.returncode != 0:
            print(
                f"layer {layer}: {title} · проверок: {completed} · FAIL: {label}",
                file=sys.stderr,
            )
            # Интерфейс gate намеренно двоичный: детали (включая 2 — ошибка
            # раннера) уже напечатаны дочерней командой, а CI нужен один
            # предсказуемый красный код для любого слоя.
            return 1
    print(f"layer {layer}: {title} · проверок: {completed}")
    return 0


def registered_sdks() -> list[dict[str, str]]:
    """Читает будущий реестр P6.8, не подменяя его вторым носителем ревизии.

    Пока реестр не создан, SDK отсутствуют по факту. Это не делает слои 3/4
    пустыми: у них есть обязательные проверки раннера и эталона соответственно.
    """
    path = ROOT / "conformance/sdks.json"
    if not path.exists():
        print("  → реестр SDK ещё не создан (P6.8); зарегистрированных SDK: 0")
        return []
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        entries = document["sdks"]
    except (OSError, json.JSONDecodeError, KeyError, TypeError) as error:
        raise ValueError(f"нечитаемый реестр SDK {path}: {error}") from error
    if not isinstance(entries, list):
        raise ValueError("conformance/sdks.json: sdks должен быть массивом")
    result: list[dict[str, str]] = []
    for entry in entries:
        if not isinstance(entry, dict):
            raise ValueError("conformance/sdks.json: запись SDK должна быть object")
        required = ("id", "adapter_command", "test_command")
        if any(not isinstance(entry.get(key), str) or not entry[key] for key in required):
            raise ValueError("conformance/sdks.json: SDK требует непустые id, adapter_command и test_command")
        result.append({key: entry[key] for key in required})
    print(f"  → зарегистрированных SDK: {len(result)}")
    return result


def layer_one() -> int:
    return run_checks(1, "lint и структура ревизии", [
        ("нормативный lint", command("python3", "tools/spec_lint.py", REVISION)),
        ("структура фикстур", command("python3", "tools/spec_lint.py", "--fixtures-check", FIXTURES)),
    ])


def layer_two() -> int:
    # Ветка C DR-02 §6: ручных DTO пока нет, поэтому свежесть SDK-части не
    # подменяется вымышленной проверкой. Этот факт печатается до результата слоя.
    print("  → SDK-часть слоя 2 — без предмета, ожидает решения владельца по `DR-02` §6")
    return run_checks(2, "свежесть производных артефактов ревизии", [
        ("индекс правил", command("python3", "tools/rules_index.py", "--check", REVISION)),
        ("закрытые схемы авторинга", command("python3", "tools/close_schema.py", REVISION, "--check")),
        ("отчёт покрытия", command("python3", "tools/spec_lint.py", REVISION, "--coverage", "--check")),
    ])


def layer_three() -> int:
    # Тесты раннера — непустой предмет слоя, пока P7/P8 ещё не зарегистрировали SDK.
    checks = [
        ("PHPUnit conformance", command("conformance/vendor/bin/phpunit", "--configuration", "conformance/phpunit.xml")),
    ]
    try:
        sdks = registered_sdks()
    except ValueError as error:
        print(f"layer 3: некорректный реестр SDK · проверок: 0 · FAIL: {error}", file=sys.stderr)
        return 1
    checks.extend((f"тесты SDK {sdk['id']}", shlex.split(sdk["test_command"])) for sdk in sdks)
    return run_checks(3, "модульные и интеграционные тесты раннера и SDK", checks)


def layer_four() -> int:
    # Lockstep идёт первым: без общего якоря ревизии последующие прогоны SDK
    # не доказывают, что исполняют тот же замороженный корпус.
    checks = [
        ("frozen 2026-08 bytes", command("git", "diff", "--exit-code", "--", FROZEN_REVISION, FROZEN_FIXTURES)),
        ("lockstep ревизии и манифестов SDK", command(
            "php", "conformance/bin/lockstep", "--revision-dir", REVISION,
        )),
        ("эталонный адаптер 2026-08 regression", command(
            "php", "conformance/bin/run", "--adapter", REFERENCE_ADAPTER,
            "--vectors", FROZEN_FIXTURES, "--spec-rev", "2026-08",
            "--baseline", "conformance/baseline/reference.json",
            "--report", "out/ci/reference-2026-08.json",
        )),
        ("selftest harness-протокола", command(
            "php", "conformance/bin/harness-selftest", "--cases", f"{FIXTURES}/harness",
        )),
        ("P6 integrated gate", command("php", "conformance/bin/p6-gate")),
    ]
    try:
        sdks = registered_sdks()
    except ValueError as error:
        print(f"layer 4: некорректный реестр SDK · проверок: 0 · FAIL: {error}", file=sys.stderr)
        return 1
    return run_checks(4, "эталонный conformance, golden, harness и SDK", checks)


LAYERS = {1: layer_one, 2: layer_two, 3: layer_three, 4: layer_four}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--layer", type=int, choices=sorted(LAYERS), help="запустить один слой")
    group.add_argument("--all", action="store_true", help="запустить слои 1→4 и остановиться на первом отказе")
    args = parser.parse_args()

    sequence = sorted(LAYERS) if args.all else [args.layer]
    for layer in sequence:
        exit_code = LAYERS[layer]()
        if exit_code != 0:
            return exit_code
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
