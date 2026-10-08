#!/usr/bin/env python3
"""CI/supply-chain structural policy checker (P9.1). Read-only; no GitHub mutations."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

try:
    import yaml
except ImportError:  # pragma: no cover
    yaml = None  # type: ignore[assignment]

ROOT = Path(__file__).resolve().parents[1]
LOCK = ROOT / ".ci/supply-chain-lock.json"
WORKFLOWS = ROOT / ".github/workflows"
FINDING = (
    ROOT
    / "verification/findings/P9-ci-capabilities.md"
)
SHA_RE = re.compile(r"^[0-9a-f]{40}$", re.IGNORECASE)
MUTABLE_ACTION_RE = re.compile(
    r"^[\w.-]+/[\w.-]+@(v\d+|main|master|latest|[\w./-]+)$",
    re.IGNORECASE,
)
PRIVILEGED_EVENTS = frozenset({"pull_request_target", "workflow_run"})


@dataclass(frozen=True)
class Violation:
    rule_id: str
    path: str
    message: str

    def as_dict(self) -> dict[str, str]:
        return {"rule_id": self.rule_id, "path": self.path, "message": self.message}


def load_yaml(text: str) -> Any:
    if yaml is None:
        raise RuntimeError("PyYAML is required: python3 -m pip install pyyaml")
    return yaml.safe_load(text)


def load_workflow(path: Path) -> Any:
    return load_yaml(path.read_text(encoding="utf-8"))


def iter_job_nodes(document: dict[str, Any]) -> Iterable[tuple[str, dict[str, Any]]]:
    jobs = document.get("jobs") or {}
    if not isinstance(jobs, dict):
        return
    for name, job in jobs.items():
        if isinstance(job, dict):
            yield name, job


def iter_steps(job: dict[str, Any]) -> Iterable[dict[str, Any]]:
    steps = job.get("steps") or []
    if not isinstance(steps, list):
        return
    for step in steps:
        if isinstance(step, dict):
            yield step


def action_uses(step: dict[str, Any]) -> str | None:
    uses = step.get("uses")
    return uses if isinstance(uses, str) else None


def is_pinned_action(ref: str) -> bool:
    if "@" not in ref:
        return False
    _repo, pin = ref.rsplit("@", 1)
    if "${{" in pin:
        return False
    return bool(SHA_RE.match(pin))


def workflow_permissions_empty(document: dict[str, Any]) -> bool:
    perms = document.get("permissions")
    return perms == {} or perms is None and False


def display_path(path: Path) -> str:
    try:
        return path.relative_to(ROOT).as_posix()
    except ValueError:
        return path.as_posix()


def check_workflow_document(path: Path, document: dict[str, Any]) -> list[Violation]:
    violations: list[Violation] = []
    rel = display_path(path)
    lock = load_lock()
    lock_actions = {
        str(entry.get("id")): str(entry.get("ref_immutable"))
        for entry in lock.get("github_actions", [])
        if isinstance(entry, dict)
    }
    sha_actions = {
        str(entry.get("ref_immutable")): str(entry.get("id"))
        for entry in lock.get("github_actions", [])
        if isinstance(entry, dict)
    }

    on = document.get("on") or document.get(True)
    events: set[str] = set()
    if isinstance(on, str):
        events.add(on)
    elif isinstance(on, list):
        events.update(str(x) for x in on)
    elif isinstance(on, dict):
        events.update(str(k) for k in on)

    for event in events:
        if event in PRIVILEGED_EVENTS:
            violations.append(
                Violation(
                    "SC-EVT-001",
                    rel,
                    f"forbidden privileged trigger `{event}`",
                )
            )

    perms = document.get("permissions", "__missing__")
    if perms != {}:
        violations.append(
            Violation(
                "SC-PERM-001",
                rel,
                "workflow must declare top-level `permissions: {}`",
            )
        )

    if document.get("concurrency") is None:
        violations.append(
            Violation(
                "SC-CONC-001",
                rel,
                "workflow must declare `concurrency`",
            )
        )

    for job_name, job in iter_job_nodes(document):
        if job.get("timeout-minutes") is None:
            violations.append(
                Violation(
                    "SC-TIME-001",
                    f"{rel}#jobs.{job_name}",
                    "job missing `timeout-minutes`",
                )
            )
        if job.get("secrets") or job.get("environment"):
            violations.append(
                Violation(
                    "SC-PLN-001",
                    f"{rel}#jobs.{job_name}",
                    "untrusted plane must not bind secrets or environments on PR CI",
                )
            )
        container = job.get("container")
        if isinstance(container, dict):
            image = container.get("image", "")
            if isinstance(image, str) and image and "@sha256:" not in image:
                violations.append(
                    Violation(
                        "SC-CONTAINER-001",
                        f"{rel}#jobs.{job_name}",
                        "container image must be digest-pinned",
                    )
                )
        services = job.get("services")
        if isinstance(services, dict):
            for service_name, service in services.items():
                image = service.get("image", "") if isinstance(service, dict) else service
                if not isinstance(image, str) or "@sha256:" not in image:
                    violations.append(
                        Violation(
                            "SC-CONTAINER-002",
                            f"{rel}#jobs.{job_name}.services.{service_name}",
                            "service container image must be digest-pinned",
                        )
                    )
        for step in iter_steps(job):
            uses = action_uses(step)
            if not uses:
                continue
            if "${{" in uses:
                violations.append(
                    Violation(
                        "SC-EXPR-001",
                        f"{rel}#jobs.{job_name}",
                        f"dynamic action ref forbidden: {uses}",
                    )
                )
                continue
            if "/" not in uses:
                continue
            if uses.startswith("./") or uses.startswith("../"):
                continue
            if not is_pinned_action(uses):
                violations.append(
                    Violation(
                        "SC-PIN-001",
                        f"{rel}#jobs.{job_name}",
                        f"third-party action not pinned to 40-char SHA: {uses}",
                    )
                )
                if MUTABLE_ACTION_RE.match(uses):
                    violations.append(
                        Violation(
                            "SC-PIN-002",
                            f"{rel}#jobs.{job_name}",
                            f"mutable action ref: {uses}",
                        )
                    )
            else:
                repo, pin = uses.rsplit("@", 1)
                locked_pin = lock_actions.get(repo)
                if locked_pin is None:
                    owner = sha_actions.get(pin)
                    message = f"action missing from supply-chain lock: {repo}"
                    if owner is not None:
                        message = f"action repository mismatch: {repo}@{pin} is locked for {owner}"
                    violations.append(
                        Violation("SC-LOCK-001", f"{rel}#jobs.{job_name}", message)
                    )
                elif locked_pin != pin:
                    violations.append(
                        Violation(
                            "SC-LOCK-002",
                            f"{rel}#jobs.{job_name}",
                            f"action SHA mismatch: {repo}@{pin}; lock has {locked_pin}",
                        )
                    )
            if uses.startswith("actions/checkout@") and is_pinned_action(uses):
                with_block = step.get("with") or {}
                if isinstance(with_block, dict) and with_block.get("persist-credentials") is not False:
                    violations.append(
                        Violation(
                            "SC-CHK-001",
                            f"{rel}#jobs.{job_name}",
                            "checkout must set persist-credentials: false",
                        )
                    )
    return violations


def check_workflow_file(path: Path) -> list[Violation]:
    document = load_workflow(path)
    if not isinstance(document, dict):
        return [
            Violation("SC-YAML-001", path.relative_to(ROOT).as_posix(), "workflow root must be a mapping")
        ]
    return check_workflow_document(path, document)


def check_all_workflows(directory: Path = WORKFLOWS) -> list[Violation]:
    violations: list[Violation] = []
    for path in sorted(directory.glob("*.yml")) + sorted(directory.glob("*.yaml")):
        violations.extend(check_workflow_file(path))
    return violations


def validate_lock_document(document: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if document.get("schema_version") != "agentools.supply-chain-lock/v1":
        errors.append("schema_version must be agentools.supply-chain-lock/v1")
    for key in ("github_actions", "cli_tools"):
        entries = document.get(key)
        if not isinstance(entries, list) or not entries:
            errors.append(f"{key} must be a non-empty list")
    action_ids: set[str] = set()
    action_pairs: set[tuple[str, str]] = set()
    for entry in document.get("github_actions", []) or []:
        if not isinstance(entry, dict):
            errors.append("github_actions entry must be object")
            continue
        for field in ("id", "ref_human", "ref_immutable", "source", "license"):
            if not entry.get(field):
                errors.append(f"github_actions missing {field}")
        ref = str(entry.get("ref_immutable", ""))
        if not SHA_RE.match(ref):
            errors.append(f"invalid action SHA for {entry.get('id')}")
        action_id = str(entry.get("id", ""))
        if action_id in action_ids:
            errors.append(f"duplicate action id: {action_id}")
        action_ids.add(action_id)
        pair = (action_id, ref)
        if pair in action_pairs:
            errors.append(f"duplicate action repo/SHA row: {action_id}@{ref}")
        action_pairs.add(pair)
        expected_source = f"https://github.com/{action_id}"
        if entry.get("source") != expected_source:
            errors.append(f"invalid source for {action_id}: expected {expected_source}")
    for entry in document.get("cli_tools", []) or []:
        if not isinstance(entry, dict):
            errors.append("cli_tools entry must be object")
            continue
        for field in ("id", "version", "artifact", "download_url", "sha256", "license"):
            if not entry.get(field):
                errors.append(f"cli_tools missing {field}")
        digest = str(entry.get("sha256", ""))
        if not re.fullmatch(r"[0-9a-f]{64}", digest):
            errors.append(f"invalid sha256 for {entry.get('id')}")
    return errors


def load_lock(path: Path = LOCK) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def check_lock(path: Path = LOCK) -> list[str]:
    try:
        document = load_lock(path)
    except (OSError, json.JSONDecodeError) as error:
        return [f"lock unreadable: {error}"]
    if not isinstance(document, dict):
        return ["lock root must be object"]
    return validate_lock_document(document)


def lock_digest(path: Path = LOCK) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_baseline_report(violations: list[Violation], path: Path) -> None:
    payload = {
        "schema_version": "agentools.ci-policy-baseline/v1",
        "workflow_count": len(list(WORKFLOWS.glob("*.yml"))) + len(list(WORKFLOWS.glob("*.yaml"))),
        "lock_sha256": lock_digest(),
        "verdict": "red" if violations else "green",
        "violations": [v.as_dict() for v in violations],
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def cmd_check_workflows(_: argparse.Namespace) -> int:
    violations = check_all_workflows()
    for item in violations:
        print(f"{item.rule_id} {item.path}: {item.message}", file=sys.stderr)
    print(f"ci-policy: {len(violations)} violation(s)")
    return 0 if not violations else 1


def cmd_check_lock(_: argparse.Namespace) -> int:
    errors = check_lock()
    for error in errors:
        print(error, file=sys.stderr)
    if errors:
        print("ci-policy lock: FAIL", file=sys.stderr)
        return 1
    print(f"ci-policy lock: ok ({lock_digest()[:12]}…)")
    return 0


def cmd_baseline(args: argparse.Namespace) -> int:
    violations = check_all_workflows()
    out = Path(args.output) if args.output else ROOT / "verification/artifacts/P9-workflow-baseline.json"
    write_baseline_report(violations, out)
    for item in violations:
        print(f"{item.rule_id} {item.path}: {item.message}")
    print(f"baseline: {len(violations)} violation(s) -> {out.relative_to(ROOT)}")
    return 0


def cmd_test(_: argparse.Namespace) -> int:
    suite = unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__])
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    return 0 if result.wasSuccessful() else 1


class CiPolicyTests(unittest.TestCase):
    def test_lock_is_valid(self) -> None:
        self.assertEqual(check_lock(), [])

    def test_mutable_tag_is_red(self) -> None:
        doc = {
            "on": "pull_request",
            "permissions": {},
            "concurrency": {"group": "x", "cancel-in-progress": True},
            "jobs": {
                "t": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 10,
                    "steps": [{"uses": "actions/checkout@v4"}],
                }
            },
        }
        hits = check_workflow_document(Path("fixture.yml"), doc)
        rules = {v.rule_id for v in hits}
        self.assertIn("SC-PIN-001", rules)
        self.assertIn("SC-PIN-002", rules)

    def test_least_privilege_fixture_green(self) -> None:
        sha = "11d5960a326750d5838078e36cf38b85af677262"
        doc = {
            "on": "pull_request",
            "permissions": {},
            "concurrency": {"group": "ci-${{ github.ref }}", "cancel-in-progress": True},
            "jobs": {
                "verify": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 30,
                    "permissions": {"contents": "read"},
                    "steps": [
                        {
                            "uses": f"actions/checkout@{sha}",
                            "with": {"persist-credentials": False},
                        },
                        {"run": "python3 tools/ci_gate.py --layer 1"},
                    ],
                }
            },
        }
        self.assertEqual(check_workflow_document(Path("good.yml"), doc), [])

    def test_privileged_event_is_red(self) -> None:
        doc = {
            "on": "pull_request_target",
            "permissions": {},
            "concurrency": {"group": "x"},
            "jobs": {"x": {"runs-on": "ubuntu-latest", "timeout-minutes": 1, "steps": []}},
        }
        rules = [v.rule_id for v in check_workflow_document(Path("bad.yml"), doc)]
        self.assertIn("SC-EVT-001", rules)

    def test_implicit_permissions_is_red(self) -> None:
        doc = {
            "on": "push",
            "jobs": {"x": {"runs-on": "ubuntu-latest", "timeout-minutes": 1, "steps": []}},
        }
        rules = {v.rule_id for v in check_workflow_document(Path("bad.yml"), doc)}
        self.assertIn("SC-PERM-001", rules)
        self.assertIn("SC-CONC-001", rules)

    def test_current_ci_workflows_are_policy_green(self) -> None:
        violations: list[Violation] = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            violations.extend(check_workflow_file(path))
        self.assertEqual(violations, [])

    def _workflow_with_service(self, service: object) -> dict[str, Any]:
        return {
            "on": "push",
            "permissions": {},
            "concurrency": {"group": "g"},
            "jobs": {
                "x": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 5,
                    "services": {"postgres": service},
                    "steps": [],
                }
            },
        }

    def test_tag_only_service_image_is_red(self) -> None:
        for service in ({"image": "postgres:17-alpine"}, "postgres:17-alpine", {"ports": ["5432:5432"]}):
            rules = {v.rule_id for v in check_workflow_document(Path("svc.yml"), self._workflow_with_service(service))}
            self.assertIn("SC-CONTAINER-002", rules, service)

    def test_digest_pinned_service_image_is_green(self) -> None:
        image = "postgres:17.11-alpine@sha256:" + "0" * 64
        doc = self._workflow_with_service({"image": image})
        self.assertEqual(check_workflow_document(Path("svc.yml"), doc), [])

    def test_dynamic_action_ref_is_red(self) -> None:
        doc = {
            "on": "push",
            "permissions": {},
            "concurrency": {"group": "g"},
            "jobs": {
                "x": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 5,
                    "steps": [{"uses": "actions/checkout@${{ github.sha }}"}],
                }
            },
        }
        rules = {v.rule_id for v in check_workflow_document(Path("dyn.yml"), doc)}
        self.assertIn("SC-EXPR-001", rules)

    def _workflow_with_action(self, uses: str) -> dict[str, Any]:
        return {
            "on": "push",
            "permissions": {},
            "concurrency": {"group": "g"},
            "jobs": {
                "x": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 5,
                    "steps": [{"uses": uses}],
                }
            },
        }

    def test_sha_from_wrong_repository_is_red(self) -> None:
        uses = "actions/upload-artifact@0057852bfaa89a56745cba8c7296529d2fc39830"
        rules = {
            v.rule_id
            for v in check_workflow_document(
                Path("wrong-repo.yml"), self._workflow_with_action(uses)
            )
        }
        self.assertIn("SC-LOCK-002", rules)

    def test_unknown_action_is_red(self) -> None:
        uses = "example/unknown@1111111111111111111111111111111111111111"
        rules = {
            v.rule_id
            for v in check_workflow_document(Path("unknown.yml"), self._workflow_with_action(uses))
        }
        self.assertIn("SC-LOCK-001", rules)

    def test_stale_action_sha_is_red(self) -> None:
        uses = "actions/checkout@1111111111111111111111111111111111111111"
        rules = {
            v.rule_id
            for v in check_workflow_document(Path("stale.yml"), self._workflow_with_action(uses))
        }
        self.assertIn("SC-LOCK-002", rules)

    def test_duplicate_action_id_is_red(self) -> None:
        lock = load_lock()
        lock["github_actions"].append(dict(lock["github_actions"][0]))
        errors = validate_lock_document(lock)
        self.assertTrue(any("duplicate action id" in error for error in errors))
        self.assertTrue(any("duplicate action repo/SHA row" in error for error in errors))

    def test_invalid_action_source_is_red(self) -> None:
        lock = load_lock()
        lock["github_actions"][0]["source"] = "https://github.com/example/wrong"
        errors = validate_lock_document(lock)
        self.assertTrue(any("invalid source" in error for error in errors))


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="CI/supply-chain policy checker (P9.1)")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check-workflows", help="Validate workflow YAML under .github/workflows").set_defaults(
        func=cmd_check_workflows
    )
    sub.add_parser("check-lock", help="Validate .ci/supply-chain-lock.json").set_defaults(func=cmd_check_lock)
    baseline = sub.add_parser("baseline", help="Write honest workflow violation baseline JSON")
    baseline.add_argument(
        "--output",
        help="Output path (default: plans/.../artifacts/P9-workflow-baseline.json)",
    )
    baseline.set_defaults(func=cmd_baseline)
    sub.add_parser("test", help="Run policy unit tests").set_defaults(func=cmd_test)
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
