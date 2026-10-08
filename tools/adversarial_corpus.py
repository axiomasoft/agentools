#!/usr/bin/env python3
"""Deterministic language-neutral adversarial corpus (P8.2)."""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any, Iterator

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "verification"
MANIFEST = REPO / "fixtures/hardening/adversarial/manifest.v1.json"
CORPUS_DIR = REPO / "fixtures/hardening/adversarial/corpus/v1"
CASES_PATH = CORPUS_DIR / "cases.jsonl"
META_PATH = CORPUS_DIR / "meta.json"
REGRESSION_DIR = REPO / "fixtures/hardening/adversarial/regressions"
RECEIPT = PLAN / "artifacts/P8-property-fuzz.json"
ORACLE_INPUT = REPO / "conformance/oracles/jcs/input"
ORACLE_OUTPUT = REPO / "conformance/oracles/jcs/output"
PHP_EVAL = REPO / "php/tests/Adversarial/eval_case.php"
DART_EVAL = REPO / "dart/agentools/test/adversarial/eval_case.dart"
GENERATOR_VERSION = "adversarial-corpus/1.0.0"
BOUNDED_SEED = 1
BOUNDED_BUDGET = 64
TOKEN_CANONICAL = '{"action_id":"act-1","alg":"HS256","args_fingerprint":"fp","aud":"confirmation","descriptor_digest":"dd","expires_at":"2026-09-20T12:00:00+00:00","iat":"2026-09-20T10:00:00+00:00","iss":"agentools","jti":"00000000000000000000000000000001","kid":"k1","nbf":"2026-09-20T10:00:00+00:00","principal":"user-a","proposal_id":"p-1","proposal_version":1,"purpose":"confirmation","tenant_id":"tenant-a","tool_name":"tool","v":1}'
TOKEN_SECRET = b"k" * 32


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(",", ":")) + "\n"


class CounterPrng:
    def __init__(self, seed: int) -> None:
        self.seed = seed
        self.counter = 0

    def block(self) -> bytes:
        payload = f"{self.seed}:{self.counter}".encode()
        self.counter += 1
        return hashlib.sha256(payload).digest()

    def randint(self, lo: int, hi: int) -> int:
        span = hi - lo + 1
        return lo + int.from_bytes(self.block()[:8], "big") % span

    def choice(self, items: list[Any]) -> Any:
        return items[self.randint(0, len(items) - 1)]


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def issue_token() -> str:
    mac = hmac.new(TOKEN_SECRET, TOKEN_CANONICAL.encode(), hashlib.sha256).digest()
    return "at1." + b64url(TOKEN_CANONICAL.encode()) + "." + b64url(mac)


def flip_bit(token: str, bit_index: int) -> str:
    raw = bytearray(token.encode("ascii"))
    byte_i = bit_index // 8
    raw[byte_i % len(raw)] ^= 1 << (bit_index % 8)
    return raw.decode("latin1")


def nested_parens(depth: int) -> str:
    return ("(" * depth) + "true" + (")" * depth)


def case_record(case_id: str, family: str, seed: int, index: int, request: dict[str, Any], expect: dict[str, Any]) -> dict[str, Any]:
    payload = canonical_json(request).encode()
    return {
        "case_id": case_id,
        "family": family,
        "generator_version": GENERATOR_VERSION,
        "seed": seed,
        "case_index": index,
        "input_sha256": sha256_bytes(payload),
        "request": request,
        "expect": expect,
    }


def iter_cases(seed: int = BOUNDED_SEED, budget: int = BOUNDED_BUDGET) -> Iterator[dict[str, Any]]:
    index = 0
    for path in sorted(ORACLE_INPUT.glob("*.json")):
        expected = (ORACLE_OUTPUT / path.name).read_text(encoding="utf-8").rstrip("\n")
        raw = path.read_text(encoding="utf-8")
        request = {"op": "jcs", "json": raw, "case_id": f"JCS-ORACLE-{path.stem}"}
        yield case_record(request["case_id"], "jcs.oracle", seed, index, request, {"class": "accept", "bytes": expected})
        index += 1

    prng = CounterPrng(seed)
    typed_templates = [
        '{"z":1,"a":2}',
        '{"\\uFFFD":1,"\\uD800\\uDC00":2}',
        '{"b":[1,2],"a":{"y":true,"x":null}}',
        '{"n":0,"s":"slash/path","u":"café"}',
        ' [ 1 , { "b" : false , "a" : true } ] ',
    ]
    for template in typed_templates:
        if index >= budget:
            break
        request = {"op": "jcs", "json": template, "case_id": f"JCS-TYPED-{index:04d}"}
        yield case_record(request["case_id"], "jcs.typed", seed, index, request, {"class": "accept"})
        index += 1

    raw_invalid = [
        ('{"a":1,"a":2}', "reject.duplicate_name"),
        ("{", "reject.invalid_json"),
        ("[1,2,]", "reject.invalid_json"),
        ('{"a":NaN}', "reject.invalid_json"),
        ("true false", "reject.invalid_json"),
        ('{"a":1,}', "reject.invalid_json"),
    ]
    for raw, cls in raw_invalid:
        if index >= budget:
            break
        request = {"op": "jcs", "json": raw, "case_id": f"JCS-RAW-{index:04d}"}
        yield case_record(request["case_id"], "jcs.raw-invalid", seed, index, request, {"class": cls})
        index += 1

    token = issue_token()
    request = {"op": "token_parse", "token": token, "now": "2026-09-20T11:00:00+00:00", "case_id": "TKN-VALID"}
    yield case_record("TKN-VALID", "token.valid", seed, index, request, {"class": "accept"})
    index += 1
    for _ in range(4):
        if index >= budget:
            break
        mutated = flip_bit(token, prng.randint(0, len(token) * 8 - 1))
        request = {"op": "token_parse", "token": mutated, "now": "2026-09-20T11:00:00+00:00", "case_id": f"TKN-BIT-{index:04d}"}
        yield case_record(request["case_id"], "token.onebit", seed, index, request, {"class": "reject.token"})
        index += 1

    for depth, cls in ((31, "accept"), (32, "accept"), (33, "reject.limit")):
        request = {"op": "expression", "source": nested_parens(depth), "case_id": f"LIM-DEPTH-{depth}"}
        expect: dict[str, Any] = {"class": cls}
        if cls != "accept":
            expect["code"] = "expression.max_depth"
        yield case_record(request["case_id"], "limits.depth", seed, index, request, expect)
        index += 1

    for count, cls in ((63, "accept"), (64, "accept"), (65, "reject.limit")):
        ids = [f"id-{i}" for i in range(count)]
        request = {"op": "baggage", "ids": ids, "case_id": f"LIM-BAG-{count}"}
        expect = {"class": cls}
        if cls != "accept":
            expect["code"] = "agentools.budget.baggage_limit_exceeded"
        yield case_record(request["case_id"], "limits.baggage", seed, index, request, expect)
        index += 1

    request = {"op": "expression", "source": '$ctx.today == exec("id")', "case_id": "STATE-HOST-CODE"}
    yield case_record("STATE-HOST-CODE", "state.illegal", seed, index, request, {"class": "reject.limit"})
    index += 1
    _ = prng


def write_corpus(seed: int = BOUNDED_SEED, budget: int = BOUNDED_BUDGET) -> list[dict[str, Any]]:
    CORPUS_DIR.mkdir(parents=True, exist_ok=True)
    cases = list(iter_cases(seed, budget))
    with CASES_PATH.open("w", encoding="utf-8") as handle:
        for case in cases:
            handle.write(canonical_json(case).rstrip("\n") + "\n")
    meta = {
        "generator_version": GENERATOR_VERSION,
        "seed": seed,
        "case_budget": budget,
        "case_count": len(cases),
        "manifest_sha256": sha256_file(MANIFEST),
        "cases_sha256": sha256_file(CASES_PATH),
        "locale": "C",
        "timezone": "UTC",
    }
    META_PATH.write_text(canonical_json(meta), encoding="utf-8")
    return cases


def load_cases(path: Path = CASES_PATH) -> list[dict[str, Any]]:
    cases = []
    with path.open(encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                cases.append(json.loads(line))
    return cases


def replay_input(generator_version: str, seed: int, case_index: int) -> dict[str, Any]:
    for case in iter_cases(seed, BOUNDED_BUDGET):
        if case["generator_version"] == generator_version and case["case_index"] == case_index:
            return case
    raise KeyError(f"missing case {generator_version}/{seed}/{case_index}")


def run_eval(command: list[str], cwd: Path, requests: list[dict[str, Any]], extra_env: dict[str, str] | None = None) -> list[dict[str, Any]]:
    payload = "".join(canonical_json(item["request"] | {"case_id": item["case_id"]}) for item in requests)
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    proc = subprocess.run(command, input=payload, cwd=str(cwd), env=env, text=True, capture_output=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"eval failed ({command}): {proc.stderr}")
    lines = [line for line in proc.stdout.splitlines() if line.strip()]
    return [json.loads(line) for line in lines]


def php_eval(requests: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return run_eval(["php", str(PHP_EVAL)], REPO, requests)


def dart_eval(requests: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return run_eval(["dart", str(DART_EVAL)], REPO / "dart/agentools", requests)


def compare_row(case: dict[str, Any], php: dict[str, Any], dart: dict[str, Any]) -> dict[str, Any]:
    expect = case["expect"]
    php_ok = php.get("class") == expect["class"]
    dart_ok = dart.get("class") == expect["class"]
    if "bytes" in expect:
        php_ok = php_ok and php.get("bytes") == expect["bytes"]
        dart_ok = dart_ok and dart.get("bytes") == expect["bytes"]
    if "code" in expect:
        php_ok = php_ok and php.get("code") == expect["code"]
        dart_ok = dart_ok and dart.get("code") == expect["code"]
    match = php.get("class") == dart.get("class")
    if "bytes" in expect or expect.get("class") == "accept":
        match = match and php.get("bytes") == dart.get("bytes")
    if "code" in expect:
        match = match and php.get("code") == dart.get("code")
    first_delta = None
    if php.get("bytes") != dart.get("bytes") and php.get("bytes") and dart.get("bytes"):
        left, right = php["bytes"], dart["bytes"]
        for offset, (a, b) in enumerate(zip(left, right)):
            if a != b:
                first_delta = {"offset": offset, "php": a, "dart": b}
                break
        if first_delta is None:
            first_delta = {"offset": min(len(left), len(right)), "php_len": len(left), "dart_len": len(right)}
    return {
        "case_id": case["case_id"],
        "php_ok": php_ok,
        "dart_ok": dart_ok,
        "match": match and php_ok and dart_ok,
        "php_sha256": sha256_bytes(canonical_json(php).encode()),
        "dart_sha256": sha256_bytes(canonical_json(dart).encode()),
        "first_delta": first_delta,
    }


def evaluate_cases(cases: list[dict[str, Any]], php_inject: str = "", dart_inject: str = "") -> list[dict[str, Any]]:
    php_cases = []
    dart_cases = []
    for case in cases:
        php_req = dict(case)
        dart_req = dict(case)
        if php_inject:
            php_req = {**case, "request": {**case["request"], "inject": php_inject}}
        if dart_inject:
            dart_req = {**case, "request": {**case["request"], "inject": dart_inject}}
        php_cases.append(php_req)
        dart_cases.append(dart_req)
    php_rows = php_eval(php_cases)
    dart_rows = dart_eval(dart_cases)
    if len(php_rows) != len(cases) or len(dart_rows) != len(cases):
        raise RuntimeError("eval row count mismatch")
    return [compare_row(case, php, dart) for case, php, dart in zip(cases, php_rows, dart_rows)]


def shrink_mismatch(json_text: str) -> str:
    current = json_text
    while "x" in current:
        candidate = current.replace("x", "", 1)
        case = {
            "case_id": "shrink",
            "request": {"op": "jcs", "json": candidate, "case_id": "shrink"},
            "expect": {"class": "accept"},
        }
        try:
            green = evaluate_cases([case])
            red = evaluate_cases([case], php_inject="php-ascii-sort")
        except (RuntimeError, UnicodeEncodeError, json.JSONDecodeError):
            break
        if green[0]["match"] and green[0]["php_ok"] and not red[0]["match"]:
            current = candidate
            continue
        break
    return current


def write_receipt(rows: list[dict[str, Any]], extra: dict[str, Any] | None = None) -> dict[str, Any]:
    meta = json.loads(META_PATH.read_text(encoding="utf-8"))
    failed = [row for row in rows if not row["match"]]
    receipt = {
        "schema": "p8-property-fuzz/v1",
        "generator_version": GENERATOR_VERSION,
        "manifest_sha256": sha256_file(MANIFEST),
        "corpus_sha256": sha256_file(CASES_PATH),
        "meta": meta,
        "case_count": len(rows),
        "failed": [row["case_id"] for row in failed],
        "rows": rows,
        "verdict": "green" if not failed else "red",
    }
    if extra:
        receipt.update(extra)
    RECEIPT.parent.mkdir(parents=True, exist_ok=True)
    RECEIPT.write_text(canonical_json(receipt), encoding="utf-8")
    return receipt


class CorpusTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.cases = write_corpus()

    def test_replay_is_byte_identical(self) -> None:
        first = [canonical_json(case) for case in self.cases]
        second = [canonical_json(case) for case in write_corpus()]
        self.assertEqual(first, second)
        replayed = replay_input(GENERATOR_VERSION, BOUNDED_SEED, self.cases[0]["case_index"])
        self.assertEqual(replayed["input_sha256"], self.cases[0]["input_sha256"])

    def test_ordinary_differential_green(self) -> None:
        rows = evaluate_cases(self.cases)
        failed = [row["case_id"] for row in rows if not row["match"]]
        self.assertEqual(failed, [], failed)

    def test_php_only_inject_is_red(self) -> None:
        oracle = [case for case in self.cases if case["family"] == "jcs.oracle"]
        rows = evaluate_cases(oracle, php_inject="php-ascii-sort")
        self.assertTrue(any(not row["match"] for row in rows))

    def test_dart_only_inject_is_red(self) -> None:
        dupes = [case for case in self.cases if case["expect"].get("class") == "reject.duplicate_name"]
        rows = evaluate_cases(dupes, dart_inject="dart-skip-dupes")
        self.assertTrue(any(not row["match"] for row in rows))

    def test_shrink_and_promotion(self) -> None:
        original = '{"pad":"xxxx","\\uFFFD":1,"\\uD800\\uDC00":2}'
        minimized = shrink_mismatch(original)
        self.assertLess(len(minimized), len(original))
        REGRESSION_DIR.mkdir(parents=True, exist_ok=True)
        promoted = REGRESSION_DIR / "jcs-utf16-sort.min.json"
        promoted.write_text(minimized, encoding="utf-8")
        replay = {
            "case_id": "REG-JCS-UTF16",
            "request": {"op": "jcs", "json": minimized, "case_id": "REG-JCS-UTF16"},
            "expect": {"class": "accept"},
        }
        green = evaluate_cases([replay])
        self.assertTrue(green[0]["match"])
        red = evaluate_cases([replay], php_inject="php-ascii-sort")
        self.assertFalse(red[0]["match"])


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", default="run", choices=("generate", "run", "test"))
    args = parser.parse_args(argv)
    if args.command == "generate":
        cases = write_corpus()
        print(f"wrote {len(cases)} cases")
        return 0
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(CorpusTests)
        result = unittest.TextTestRunner(verbosity=1).run(suite)
        return 0 if result.wasSuccessful() else 1
    cases = write_corpus()
    rows = evaluate_cases(cases)
    receipt = write_receipt(rows)
    if receipt["verdict"] != "green":
        print("adversarial corpus red: " + ",".join(receipt["failed"]), file=sys.stderr)
        return 1
    print(f"adversarial corpus green ({receipt['case_count']} cases)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
