#!/usr/bin/env python3
"""Multi-process PostgreSQL schedule controller (P8.4)."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import select
import signal
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from datetime import datetime, timezone
from pathlib import Path
from queue import Empty, Queue
from typing import Any
from urllib.parse import unquote, urlparse

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
MANIFEST = REPO / "fixtures/hardening/adversarial/manifest.v1.json"
SCHEDULES = REPO / "fixtures/hardening/adversarial/storage-schedules.v1.json"
RECEIPT = PLAN / "artifacts/P8-storage-chaos.json"
PHP_WORKER = REPO / "php/tests/Adversarial/storage_worker.php"
DART_WORKER = REPO / "dart/agentools/test/adversarial/storage_worker.dart"
SQL_FILES = {
    "tokens": REPO / "fixtures/hardening/sql/confirmation-consumed-tokens.v1.sql",
    "leases": REPO / "fixtures/hardening/sql/idempotency-lease.v1.sql",
    "events": REPO / "fixtures/hardening/sql/event-store.v1.sql",
}
SCHEMA_RE = re.compile(r"^p8r_[0-9a-f]{12}$")
TEST_DB_RE = re.compile(r"(?:^|_)test(?:$|_)")
FORBIDDEN_DB = {"postgres", "template0", "template1"}
LOCAL_HOSTS = {"127.0.0.1", "localhost", "::1"}
SLEEP_RE = re.compile(r"sleep", re.I)
DSN_ENV = "AGENTOOLS_TEST_PG_DSN"
USER_ENV = "AGENTOOLS_TEST_PG_USER"
PASS_ENV = "AGENTOOLS_TEST_PG_PASSWORD"


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(",", ":")) + "\n"


def digest_of(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


class PreflightError(ValueError):
    pass


def parse_dsn(raw: str) -> dict[str, str]:
    text = (raw or "").strip()
    if not text:
        raise PreflightError("missing DSN")
    if text.startswith("postgres://") or text.startswith("postgresql://"):
        parsed = urlparse(text)
        dbname = unquote((parsed.path or "").lstrip("/"))
        return {
            "host": parsed.hostname or "",
            "port": str(parsed.port or 5432),
            "dbname": dbname,
            "user": unquote(parsed.username or ""),
            "password": unquote(parsed.password or ""),
        }
    if not text.startswith("pgsql:"):
        raise PreflightError("DSN must be pgsql: or postgres://")
    fields: dict[str, str] = {}
    for part in text[6:].split(";"):
        if not part or "=" not in part:
            continue
        key, value = part.split("=", 1)
        fields[key.strip().lower()] = value.strip()
    return {
        "host": fields.get("host", ""),
        "port": fields.get("port", "5432"),
        "dbname": fields.get("dbname") or fields.get("database") or "",
        "user": fields.get("user", os.environ.get(USER_ENV, "")),
        "password": fields.get("password", os.environ.get(PASS_ENV, "")),
    }


def php_dsn(info: dict[str, str]) -> str:
    return f"pgsql:host={info['host']};port={info['port']};dbname={info['dbname']}"


def preflight_dsn(raw: str | None, *, connect: bool = False) -> dict[str, str]:
    if raw is None or not str(raw).strip():
        raise PreflightError("AGENTOOLS_TEST_PG_DSN must be set to an isolated test database")
    info = parse_dsn(raw)
    if not info["user"]:
        info["user"] = os.environ.get(USER_ENV, "")
    if not info["password"]:
        info["password"] = os.environ.get(PASS_ENV, "")
    if info["host"] not in LOCAL_HOSTS:
        raise PreflightError("host must be loopback")
    dbname = info["dbname"]
    if not dbname or dbname in FORBIDDEN_DB or not TEST_DB_RE.search(dbname):
        raise PreflightError("database name is not an isolated test database")
    if not info["user"] or not info["password"]:
        raise PreflightError("test role credentials are required")
    if connect:
        identity = psql_row(
            info,
            "SELECT current_database() AS db, current_user AS usr, inet_server_port() AS port",
        )
        if identity["db"] != dbname or identity["usr"] != info["user"]:
            raise PreflightError("connected identity does not match the configured test DSN")
    return info


def psql_exec(info: dict[str, str], sql: str, *, schema: str | None = None) -> str:
    env = os.environ.copy()
    env["PGPASSWORD"] = info["password"]
    env["PGAPPNAME"] = "agentools-p8-controller"
    body = sql
    if schema:
        if not SCHEMA_RE.match(schema):
            raise PreflightError("cleanup/observe refused: schema is outside the run namespace")
        body = f'SET search_path TO "{schema}";\n{sql}'
    completed = subprocess.run(
        [
            "psql",
            "-h",
            info["host"],
            "-p",
            info["port"],
            "-U",
            info["user"],
            "-d",
            info["dbname"],
            "-v",
            "ON_ERROR_STOP=1",
            "-A",
            "-t",
            "-F",
            "\t",
            "-c",
            body,
        ],
        check=False,
        capture_output=True,
        text=True,
        env=env,
    )
    if completed.returncode != 0:
        raise RuntimeError(completed.stderr.strip() or completed.stdout.strip() or "psql failed")
    ignored = {"SET", "CREATE SCHEMA", "DROP SCHEMA", "CREATE TABLE", "TRUNCATE TABLE", "CREATE INDEX"}
    lines = [line for line in completed.stdout.splitlines() if line.strip() and line.strip() not in ignored]
    return "\n".join(lines).rstrip("\n")


def psql_row(info: dict[str, str], sql: str, *, schema: str | None = None) -> dict[str, str]:
    raw = psql_exec(info, sql, schema=schema)
    if not raw:
        return {}
    line = raw.splitlines()[0]
    # For SELECT a, b we return positional keys when headers are omitted (-t).
    parts = line.split("\t")
    if " AS " in sql.upper() or " as " in sql:
        aliases = re.findall(r"\sAS\s+(\w+)", sql, flags=re.I)
        if aliases and len(aliases) == len(parts):
            return dict(zip(aliases, parts))
    return {"value": parts[0], "parts": "\t".join(parts)}


def load_schedules(path: Path = SCHEDULES) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    validate_schedules(payload)
    return payload


def validate_schedules(payload: dict[str, Any]) -> None:
    dumped = json.dumps(payload)
    if SLEEP_RE.search(dumped):
        raise PreflightError("schedule file contains a sleep step")
    catalog = {row["id"] for row in json.loads(MANIFEST.read_text(encoding="utf-8"))["catalog"]["schedules"]}
    declared = set(payload.get("catalog_ids") or [])
    if declared != catalog:
        raise PreflightError(f"catalog_ids mismatch: {sorted(declared)} != {sorted(catalog)}")
    seen: set[str] = set()
    for schedule in payload.get("schedules") or []:
        sid = schedule["id"]
        if sid in seen:
            raise PreflightError(f"duplicate schedule {sid}")
        seen.add(sid)
        if schedule.get("catalog_id") not in catalog:
            raise PreflightError(f"{sid} catalog_id is not in the AV manifest")
        for step in schedule.get("steps") or []:
            if step.get("op") in {"sleep", "wait", "delay"}:
                raise PreflightError(f"{sid} uses a sleep-based step")
            if "sleep" in step or "sleep_ms" in step:
                raise PreflightError(f"{sid} uses a sleep field")


def create_namespace(info: dict[str, str], run_id: str) -> str:
    schema = f"p8r_{run_id[:12]}"
    if not SCHEMA_RE.match(schema):
        raise PreflightError("run-qualified schema is invalid")
    psql_exec(info, f'CREATE SCHEMA "{schema}"')
    sql = "\n".join(path.read_text(encoding="utf-8") for path in SQL_FILES.values())
    psql_exec(info, sql, schema=schema)
    return schema


def drop_namespace(info: dict[str, str], schema: str) -> None:
    if not SCHEMA_RE.match(schema):
        raise PreflightError("cleanup refused: schema is outside the run namespace")
    psql_exec(info, f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')


class WorkerProc:
    def __init__(self, proc: subprocess.Popen[str], actor: str) -> None:
        self.proc = proc
        self.actor = actor
        self.queue: Queue[dict[str, Any]] = Queue()
        self.stderr_chunks: list[str] = []
        self.backend_pid: int | None = None
        self.pid = proc.pid
        self._reader = threading.Thread(target=self._read_stdout, daemon=True)
        self._err = threading.Thread(target=self._read_stderr, daemon=True)
        self._reader.start()
        self._err.start()

    def _read_stdout(self) -> None:
        assert self.proc.stdout is not None
        for line in self.proc.stdout:
            line = line.strip()
            if not line:
                continue
            self.queue.put(json.loads(line))

    def _read_stderr(self) -> None:
        assert self.proc.stderr is not None
        for line in self.proc.stderr:
            self.stderr_chunks.append(line)

    def send(self, message: dict[str, Any]) -> None:
        assert self.proc.stdin is not None
        self.proc.stdin.write(json.dumps(message, separators=(",", ":")) + "\n")
        self.proc.stdin.flush()

    def recv(self, timeout: float) -> dict[str, Any]:
        try:
            return self.queue.get(timeout=timeout)
        except Empty as exc:
            err = "".join(self.stderr_chunks[-20:])
            raise TimeoutError(f"{self.actor} produced no reply: {err}") from exc

    def kill(self) -> None:
        try:
            os.killpg(self.proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        try:
            self.proc.wait(timeout=2)
        except subprocess.TimeoutExpired:
            self.proc.kill()


def spawn_worker(sdk: str, actor: str, hello: dict[str, Any]) -> WorkerProc:
    if sdk == "php":
        command = ["php", str(PHP_WORKER)]
        cwd = REPO / "php"
    elif sdk == "dart":
        command = ["dart", "run", str(DART_WORKER)]
        cwd = REPO / "dart/agentools"
    else:
        raise PreflightError(f"unknown sdk {sdk}")
    proc = subprocess.Popen(
        command,
        cwd=cwd,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        start_new_session=True,
        bufsize=1,
    )
    worker = WorkerProc(proc, actor)
    ready = worker.recv(10)
    if ready.get("type") != "ready":
        raise RuntimeError(f"{actor} did not become ready: {ready}")
    worker.pid = int(ready.get("pid") or proc.pid)
    worker.send(hello)
    ack = worker.recv(10)
    if ack.get("status") != "ok":
        raise RuntimeError(f"{actor} hello failed: {ack}")
    worker.backend_pid = int(ack["backend_pid"])
    return worker


def application_name(run_id: str, schedule_id: str, actor: str) -> str:
    return f"agentools-p8:{run_id[:8]}:{schedule_id}:{actor}"[:63]


def observe_blocked(
    info: dict[str, str],
    waiter_pid: int,
    blocker_pid: int,
    timeout_ms: int,
) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_ms / 1000
    delay = 0.005
    last: dict[str, Any] = {}
    while time.monotonic() < deadline:
        raw = psql_exec(
            info,
            "SELECT waiter.pid, waiter.application_name, waiter.wait_event_type, waiter.wait_event, "
            "waiter.state, blockers.blocker "
            "FROM pg_stat_activity waiter "
            "CROSS JOIN LATERAL unnest(pg_blocking_pids(waiter.pid)) AS blockers(blocker) "
            f"WHERE waiter.pid = {int(waiter_pid)} AND blockers.blocker = {int(blocker_pid)}",
        )
        if raw:
            parts = raw.splitlines()[0].split("\t")
            last = {
                "waiter_pid": int(parts[0]),
                "application_name": parts[1],
                "wait_event_type": parts[2],
                "wait_event": parts[3],
                "state": parts[4],
                "blocker_pid": int(parts[5]),
            }
            return last
        time.sleep(delay)
        delay = min(delay * 2, 0.05)
    raise TimeoutError(f"did not observe pid {waiter_pid} blocked by {blocker_pid}: {last}")


def token_args(schedule: dict[str, Any], step: dict[str, Any]) -> dict[str, Any]:
    setup = schedule["setup"]
    token = step.get("digest") or setup.get("digest") or "token-a"
    return {
        "tenant_id": step.get("tenant_id") or setup["tenant_id"],
        "token_id": step.get("token_id") or setup["token_id"],
        "digest": digest_of(token),
        "purpose": "confirmation",
        "expires_at": "2026-09-20T12:00:00+00:00",
        "now": "2026-09-20T10:00:00+00:00",
    }


def lease_args(schedule: dict[str, Any], step: dict[str, Any]) -> dict[str, Any]:
    setup = schedule["setup"]
    return {
        "tenant_id": setup["tenant_id"],
        "tool_name": setup["tool_name"],
        "key": setup["key"],
        "fingerprint": setup["fingerprint"],
        "action_id": "action-1",
        "now": step.get("now") or "2026-09-20T10:00:00+00:00",
        "lease_seconds": 30,
    }


def event_args(schedule: dict[str, Any], step: dict[str, Any]) -> dict[str, Any]:
    setup = schedule["setup"]
    ids = step.get("batch") or setup.get("batch") or [step.get("event_id") or setup["event_id"]]
    payload = step.get("payload") or {"n": 1}
    events = []
    for event_id in ids:
        events.append(
            {
                "event_id": event_id,
                "kind": "action_created",
                "event_version": 1,
                "spec_rev": "2026-08",
                "actor_type": "user",
                "actor_id": "u-1",
                "timestamp": "2026-09-20T10:00:00+00:00",
                "payload": payload,
            }
        )
    return {
        "tenant_id": setup["tenant_id"],
        "action_id": setup["action_id"],
        "expected_prev_seq": step.get("expected_prev_seq", 0),
        "events": events,
        "limit": step.get("limit", 2),
        "after_seq": step.get("after_seq", 0),
    }


def command_for(schedule: dict[str, Any], step: dict[str, Any]) -> dict[str, Any]:
    op = step["op"]
    cmd: dict[str, Any] = {"type": "cmd", "id": step["id"], "op": op}
    if op == "consume":
        cmd["args"] = token_args(schedule, step)
    elif op in {"acquire", "heartbeat", "complete", "fail"}:
        cmd["args"] = lease_args(schedule, step)
    elif op in {"append", "append_prepare", "append_finish", "append_one", "load"}:
        cmd["args"] = event_args(schedule, step)
    return cmd


def outcome_ok(result: dict[str, Any], expect: dict[str, Any] | None) -> None:
    if not expect:
        if result.get("status") != "ok":
            raise AssertionError(f"expected ok, got {result}")
        return
    if "fault" in expect:
        if result.get("fault") != expect["fault"] and result.get("outcome") != expect["fault"]:
            raise AssertionError(f"expected fault {expect['fault']}, got {result}")
        return
    if "outcome" in expect and result.get("outcome") != expect["outcome"]:
        raise AssertionError(f"expected {expect['outcome']}, got {result}")
    if "attempt" in expect and int(result.get("attempt") or 0) != int(expect["attempt"]):
        raise AssertionError(f"expected attempt {expect['attempt']}, got {result}")
    if "has_more" in expect and bool(result.get("has_more")) != bool(expect["has_more"]):
        raise AssertionError(f"expected has_more {expect['has_more']}, got {result}")
    if "count" in expect and int(result.get("count") or 0) != int(expect["count"]):
        raise AssertionError(f"expected count {expect['count']}, got {result}")
    if "outcome" not in expect and result.get("status") == "error":
        raise AssertionError(f"expected ok, got {result}")


def verify_invariants(
    info: dict[str, str],
    schema: str,
    schedule: dict[str, Any],
) -> list[dict[str, Any]]:
    setup = schedule["setup"]
    rows: list[dict[str, Any]] = []
    for check in schedule.get("verify") or []:
        op = check["op"]
        if op == "token_row":
            tenant = check.get("tenant_id") or setup["tenant_id"]
            token_id = check.get("token_id") or setup["token_id"]
            raw = psql_exec(
                info,
                "SELECT (SELECT count(*)::text FROM confirmation_consumed_tokens "
                f"WHERE tenant_id = '{tenant}' AND token_id = '{token_id}'), "
                "coalesce((SELECT encode(token_digest, 'hex') FROM confirmation_consumed_tokens "
                f"WHERE tenant_id = '{tenant}' AND token_id = '{token_id}' LIMIT 1), '')",
                schema=schema,
            )
            parts = raw.split("\t")
            count = int(parts[0])
            digest = parts[1] if len(parts) > 1 else ""
            if count != int(check["expect_count"]):
                raise AssertionError(f"{schedule['id']} token_row count {count} != {check['expect_count']}")
            if check.get("digest_of") and count:
                expected = digest_of(check["digest_of"])
                if digest != expected:
                    raise AssertionError(f"{schedule['id']} digest mismatch")
            rows.append({"op": op, "count": count, "digest": digest})
        elif op == "lease_row":
            raw = psql_exec(
                info,
                "SELECT state, attempt::text FROM agentools_idempotency_leases "
                f"WHERE tenant_id = '{setup['tenant_id']}' AND tool_name = '{setup['tool_name']}' "
                f"AND idempotency_key = '{setup['key']}'",
                schema=schema,
            )
            if not raw:
                raise AssertionError(f"{schedule['id']} missing lease row")
            state, attempt = raw.split("\t")
            if state != check["expect_state"] or int(attempt) != int(check["expect_attempt"]):
                raise AssertionError(f"{schedule['id']} lease {state}/{attempt}")
            rows.append({"op": op, "state": state, "attempt": int(attempt)})
        elif op == "event_count":
            raw = psql_exec(
                info,
                "SELECT count(*)::text FROM agentools_events "
                f"WHERE tenant_id = '{setup['tenant_id']}' AND action_id = '{setup['action_id']}'",
                schema=schema,
            )
            count = int(raw.splitlines()[0])
            if count != int(check["expect_count"]):
                raise AssertionError(f"{schedule['id']} event_count {count} != {check['expect_count']}")
            rows.append({"op": op, "count": count})
        else:
            raise AssertionError(f"unknown verify op {op}")
    return rows


def run_schedule(
    info: dict[str, str],
    schema: str,
    run_id: str,
    sdk: str,
    schedule: dict[str, Any],
    *,
    mutant: str = "",
    timeouts: dict[str, int],
) -> dict[str, Any]:
    workers: dict[str, WorkerProc] = {}
    trace: list[dict[str, Any]] = []
    blocked: list[dict[str, Any]] = []
    try:
        for actor in schedule["actors"]:
            hello = {
                "type": "hello",
                "run_id": run_id,
                "actor": actor,
                "schema": schema,
                "sdk": sdk,
                "mutant": mutant,
                "dsn": php_dsn(info),
                "host": info["host"],
                "port": int(info["port"]),
                "dbname": info["dbname"],
                "user": info["user"],
                "password": info["password"],
                "application_name": application_name(run_id, schedule["id"], actor),
                "lock_ms": timeouts["lock_ms"],
                "statement_ms": timeouts["statement_ms"],
                "idle_in_tx_ms": timeouts["idle_in_tx_ms"],
            }
            workers[actor] = spawn_worker(sdk, actor, hello)
        pending: dict[str, dict[str, Any]] = {}
        for step in schedule["steps"]:
            op = step["op"]
            if op == "observe_blocked":
                start = dict(step["start"])
                start_id = f"{step['id']}:start"
                start["id"] = start_id
                waiter = start["actor"]
                cmd = command_for(schedule, start)
                cmd["id"] = start_id
                workers[waiter].send(cmd)
                evidence = observe_blocked(
                    info,
                    workers[waiter].backend_pid or 0,
                    workers[step["blocker"]].backend_pid or 0,
                    timeouts["observe_ms"],
                )
                blocked.append(evidence)
                pending[waiter] = {"id": start_id, "expect": step.get("expect"), "step": step["id"]}
                trace.append(
                    {
                        "id": step["id"],
                        "op": op,
                        "waiter": waiter,
                        "blocker": step["blocker"],
                        "wait_event_type": evidence.get("wait_event_type"),
                    }
                )
                continue
            actor = step["actor"]
            if op == "client_crash":
                workers[actor].kill()
                trace.append({"id": step["id"], "op": op, "fault": "client_crash"})
                continue
            if op == "query_cancel":
                pid = workers[actor].backend_pid
                psql_exec(info, f"SELECT pg_cancel_backend({int(pid)})")
                result = workers[actor].recv(timeouts["statement_ms"] / 1000 + 2)
                result["fault"] = "query_cancel"
                if actor in pending:
                    outcome_ok(result, pending.pop(actor).get("expect"))
                else:
                    outcome_ok(result, step.get("expect") or {"fault": "query_cancel"})
                trace.append({"id": step["id"], "op": op, "fault": "query_cancel", "outcome": result.get("outcome")})
                continue
            if op == "backend_terminate":
                pid = workers[actor].backend_pid
                psql_exec(info, f"SELECT pg_terminate_backend({int(pid)})")
                if actor in pending:
                    result = workers[actor].recv(timeouts["statement_ms"] / 1000 + 2)
                    result["fault"] = "backend_terminate"
                    outcome_ok(result, pending.pop(actor).get("expect"))
                    trace.append(
                        {
                            "id": step["id"],
                            "op": op,
                            "fault": "backend_terminate",
                            "outcome": result.get("outcome"),
                        }
                    )
                else:
                    trace.append({"id": step["id"], "op": op, "fault": "backend_terminate"})
                continue
            cmd = command_for(schedule, step)
            workers[actor].send(cmd)
            result = workers[actor].recv(timeouts["statement_ms"] / 1000 + 3)
            outcome_ok(result, step.get("expect"))
            if result.get("backend_pid"):
                workers[actor].backend_pid = int(result["backend_pid"])
            trace.append(
                {
                    "id": step["id"],
                    "op": op,
                    "outcome": result.get("outcome") or result.get("status"),
                    "sqlstate": result.get("sqlstate"),
                }
            )
            if pending:
                done = []
                for waiter, meta in pending.items():
                    try:
                        waited = workers[waiter].recv(0.05)
                    except TimeoutError:
                        continue
                    outcome_ok(waited, meta.get("expect"))
                    trace.append(
                        {
                            "id": meta["step"],
                            "op": "unblocked",
                            "actor": waiter,
                            "outcome": waited.get("outcome") or waited.get("fault"),
                            "sqlstate": waited.get("sqlstate"),
                        }
                    )
                    done.append(waiter)
                for waiter in done:
                    pending.pop(waiter)
        for waiter, meta in list(pending.items()):
            waited = workers[waiter].recv(timeouts["statement_ms"] / 1000 + 3)
            outcome_ok(waited, meta.get("expect"))
            trace.append(
                {
                    "id": meta["step"],
                    "op": "unblocked",
                    "actor": waiter,
                    "outcome": waited.get("outcome") or waited.get("fault"),
                    "sqlstate": waited.get("sqlstate"),
                }
            )
            pending.pop(waiter)
        invariants = verify_invariants(info, schema, schedule)
        return {
            "id": schedule["id"],
            "catalog_id": schedule["catalog_id"],
            "sdk": sdk,
            "status": "green",
            "trace": trace,
            "blocked": blocked,
            "invariants": invariants,
            "trace_sha256": sha256_bytes(canonical_json(trace).encode()),
            "invariant_sha256": sha256_bytes(canonical_json(invariants).encode()),
        }
    finally:
        for worker in workers.values():
            try:
                worker.send({"type": "cmd", "id": "shutdown", "op": "shutdown"})
            except Exception:
                pass
            worker.kill()


def reset_tables(info: dict[str, str], schema: str) -> None:
    psql_exec(
        info,
        "TRUNCATE confirmation_consumed_tokens, agentools_idempotency_leases, "
        "agentools_events, agentools_event_streams",
        schema=schema,
    )


def run_sdk(
    info: dict[str, str],
    schema: str,
    run_id: str,
    sdk: str,
    payload: dict[str, Any],
    *,
    mutant: str = "",
) -> list[dict[str, Any]]:
    results = []
    for schedule in payload["schedules"]:
        reset_tables(info, schema)
        results.append(
            run_schedule(
                info,
                schema,
                run_id,
                sdk,
                schedule,
                mutant=mutant,
                timeouts=payload["timeouts"],
            )
        )
    return results


def compare_sdks(php_rows: list[dict[str, Any]], dart_rows: list[dict[str, Any]]) -> None:
    php_map = {row["id"]: row for row in php_rows}
    dart_map = {row["id"]: row for row in dart_rows}
    if php_map.keys() != dart_map.keys():
        raise AssertionError("PHP/Dart schedule sets differ")
    for sid, php_row in php_map.items():
        dart_row = dart_map[sid]
        if php_row["invariant_sha256"] != dart_row["invariant_sha256"]:
            raise AssertionError(f"{sid} invariant sets differ")


def run_mutant(info: dict[str, str], payload: dict[str, Any], mutant: str, target_ids: set[str]) -> dict[str, Any]:
    run_id = sha256_bytes(os.urandom(16).hex().encode())[:12]
    schema = create_namespace(info, run_id)
    try:
        red: list[str] = []
        for schedule in payload["schedules"]:
            if schedule["id"] not in target_ids:
                continue
            reset_tables(info, schema)
            try:
                run_schedule(
                    info,
                    schema,
                    run_id,
                    "php",
                    schedule,
                    mutant=mutant,
                    timeouts=payload["timeouts"],
                )
            except Exception:
                red.append(schedule["id"])
        if not red:
            raise AssertionError(f"mutant {mutant} stayed green")
        return {"mutant": mutant, "red": red, "status": "red"}
    finally:
        drop_namespace(info, schema)


def emit_receipt(
    payload: dict[str, Any],
    php_rows: list[dict[str, Any]],
    dart_rows: list[dict[str, Any]],
    mutants: list[dict[str, Any]],
    identity: dict[str, str],
) -> dict[str, Any]:
    return {
        "schema": "p8-storage-chaos/v1",
        "verdict": "green",
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "manifest_sha256": sha256_file(MANIFEST),
        "schedules_sha256": sha256_file(SCHEDULES),
        "seed": payload["seed"],
        "postgres": {
            "database": identity["dbname"],
            "user": identity["user"],
            "host": identity["host"],
            "port": identity["port"],
            "server_version": identity.get("server_version", ""),
        },
        "php": php_rows,
        "dart": dart_rows,
        "mutants": mutants,
        "catalog": payload["catalog_ids"],
    }


def cmd_run(offline: bool = False) -> int:
    payload = load_schedules()
    if offline:
        print(canonical_json({"status": "offline", "schedules": len(payload["schedules"])}), end="")
        return 0
    info = preflight_dsn(os.environ.get(DSN_ENV), connect=True)
    version = psql_exec(info, "SHOW server_version")
    info = {**info, "server_version": version.splitlines()[0]}
    run_id = sha256_bytes(f"{time.time_ns()}".encode())
    schema = create_namespace(info, run_id)
    try:
        php_rows = run_sdk(info, schema, run_id, "php", payload)
        dart_rows = run_sdk(info, schema, run_id, "dart", payload)
        compare_sdks(php_rows, dart_rows)
        php_again = run_sdk(info, schema, run_id, "php", payload)
        for first, second in zip(php_rows, php_again):
            if first["trace_sha256"] != second["trace_sha256"]:
                raise AssertionError(f"{first['id']} trace is not deterministic")
        mutants = [
            run_mutant(info, payload, "unfenced", {"idc-stale"}),
            run_mutant(info, payload, "non-atomic", {"evt-roll"}),
        ]
        receipt = emit_receipt(payload, php_rows, dart_rows, mutants, info)
        RECEIPT.write_text(canonical_json(receipt), encoding="utf-8")
        print(canonical_json({"status": "green", "receipt": str(RECEIPT.relative_to(REPO))}), end="")
        return 0
    finally:
        drop_namespace(info, schema)


class StorageChaosTests(unittest.TestCase):
    def test_preflight_rejects_missing_dsn(self) -> None:
        with self.assertRaises(PreflightError):
            preflight_dsn(None)

    def test_preflight_rejects_postgres_db(self) -> None:
        with self.assertRaises(PreflightError):
            preflight_dsn("pgsql:host=127.0.0.1;port=5432;dbname=postgres")

    def test_preflight_rejects_remote_host(self) -> None:
        os.environ[USER_ENV] = "agentools"
        os.environ[PASS_ENV] = "agentools-test"
        with self.assertRaises(PreflightError):
            preflight_dsn("pgsql:host=10.0.0.8;port=5432;dbname=agentools_test")

    def test_schedule_has_no_sleep(self) -> None:
        payload = load_schedules()
        self.assertGreaterEqual(len(payload["schedules"]), 10)

    def test_sleep_schedule_is_rejected(self) -> None:
        with self.assertRaises(PreflightError):
            validate_schedules({"catalog_ids": ["SCH-TKN-CONCURRENT", "SCH-IDC-TAKEOVER", "SCH-EVT-CONCURRENT"], "schedules": [{"id": "x", "catalog_id": "SCH-TKN-CONCURRENT", "steps": [{"op": "sleep"}]}]})

    def test_cleanup_refuses_foreign_schema(self) -> None:
        with self.assertRaises(PreflightError):
            drop_namespace({"host": "127.0.0.1", "port": "1", "dbname": "agentools_test", "user": "u", "password": "p"}, "public")

    def test_worker_sql_matches_stores(self) -> None:
        php_store = REPO.joinpath("php/src/Confirmation/PdoPostgresConfirmationTokenStore.php").read_text(encoding="utf-8")
        php_lease = REPO.joinpath("php/src/Idempotency/PdoPostgresIdempotencyStore.php").read_text(encoding="utf-8")
        dart_store = REPO.joinpath("dart/agentools/lib/src/confirmation/sql_confirmation_token_store.dart").read_text(encoding="utf-8")
        dart_lease = REPO.joinpath("dart/agentools/lib/src/idempotency/sql_idempotency_store.dart").read_text(encoding="utf-8")
        php = PHP_WORKER.read_text(encoding="utf-8")
        dart = DART_WORKER.read_text(encoding="utf-8")
        self.assertIn("ON CONFLICT (tenant_id, token_id) DO NOTHING", php_store)
        self.assertIn("ON CONFLICT (tenant_id, token_id) DO NOTHING", dart_store)
        self.assertIn("FOR UPDATE", php)
        self.assertIn("SqlEventStore.selectHeaderSql", dart)
        self.assertIn("lease_token = :lease_token", php_lease)
        self.assertIn("lease_token = @lease_token", dart_lease)
        self.assertIn("SqlConfirmationTokenStore.consumeSql", dart)
        self.assertIn("LOCK_HEADER_SQL", php)
        self.assertIn("UNFENCED_FINISH_SQL", php)

    @unittest.skipUnless(os.environ.get("AGENTOOLS_P8_LIVE") == "1", "live PostgreSQL run is owned by storage_chaos.py run")
    def test_live_run_matches_receipt_shape(self) -> None:
        self.assertEqual(0, cmd_run(offline=False))
        receipt = json.loads(RECEIPT.read_text(encoding="utf-8"))
        self.assertEqual("green", receipt["verdict"])
        self.assertEqual(len(receipt["php"]), len(receipt["dart"]))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="P8.4 PostgreSQL schedule controller")
    parser.add_argument("command", choices=["test", "run", "preflight"])
    parser.add_argument("--offline", action="store_true")
    args = parser.parse_args(argv)
    if args.command == "preflight":
        preflight_dsn(os.environ.get(DSN_ENV), connect=not args.offline)
        print("ok")
        return 0
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(StorageChaosTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return cmd_run(offline=args.offline)


if __name__ == "__main__":
    raise SystemExit(main())
