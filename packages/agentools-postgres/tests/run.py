#!/usr/bin/env python3
"""Local isolated PostgreSQL bootstrap; no CI script or implicit developer DSN.

With an explicit AGENTOOLS_TEST_PG_DSN, the database must already have its
restricted owner and database comment agentools:isolation:v1:<database>:<owner>.
Without one, provision a private ephemeral Docker PostgreSQL and remove only
that container. phpunit.xml and the PHP lifecycle repeat the verification.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[3]
PACKAGE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from storage_chaos import parse_dsn, preflight_dsn, psql_row, php_dsn


def command(args, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, text=True, timeout=60, **kwargs)


def verify(info):
    row = psql_row(info, "SELECT current_database() AS db, current_user AS usr, "
                   "pg_get_userbyid(d.datdba) AS owner, shobj_description(d.oid, 'pg_database') AS marker, "
                   "r.rolsuper AS superuser, r.rolcreatedb AS createdb, r.rolcreaterole AS createrole, "
                   "current_setting('server_version') AS server_version FROM pg_database d "
                   "JOIN pg_roles r ON r.rolname = current_user WHERE d.datname = current_database()")
    if (not re.fullmatch(r"agentools_test_[a-z0-9_]+", info["dbname"]) or
            row.get("db") != info["dbname"] or row.get("usr") != info["user"] or
            row.get("owner") != info["user"] or
            row.get("marker") != f"agentools:isolation:v1:{info['dbname']}:{info['user']}" or
            any(row.get(key) != "f" for key in ("superuser", "createdb", "createrole"))):
        raise ValueError("database owner/name/allow marker or restricted role verification failed")
    return {"engine": "postgresql", "isolated": True, "database": row["db"], "owner": row["owner"],
            "allow_marker_verified": True, "server_version": row["server_version"]}


def main(configuration=PACKAGE / "phpunit.xml", case_prefix="pg-schema", case_names=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--filter", help="Focused PHPUnit method filter; gate runs the exact full case set")
    args = parser.parse_args()
    container = None
    token = secrets.token_hex(12)
    report = {"mode": "isolated-postgres", "tier": "isolated-postgres", "cases": []}
    try:
        env = os.environ.copy()
        if "AGENTOOLS_TEST_PG_DSN" not in env:
            database = "agentools_test_" + token
            owner = "p3_" + token
            password = secrets.token_hex(24)
            container = command(["docker", "run", "--detach", "--rm", "--label", "agentools.p3.isolated=" + token,
                                 "-e", "POSTGRES_PASSWORD=" + password, "-p", "127.0.0.1::5432", "postgres:17"]).stdout.strip()
            ready = False
            for _ in range(100):
                result = subprocess.run(["docker", "exec", container, "pg_isready", "-h", "127.0.0.1", "-U", "postgres"],
                                        capture_output=True, timeout=5)
                if result.returncode == 0:
                    ready = True
                    break
                time.sleep(0.1)
            if not ready:
                raise RuntimeError("disposable PostgreSQL did not become ready")
            # Only generated identifiers/hex secrets enter SQL in our new, labelled container.
            sql = (f"CREATE ROLE {owner} LOGIN PASSWORD '{password}';\n"
                   f"CREATE DATABASE {database} OWNER {owner};\n"
                   f"COMMENT ON DATABASE {database} IS 'agentools:isolation:v1:{database}:{owner}';\n")
            command(["docker", "exec", "-i", container, "psql", "-U", "postgres", "-v", "ON_ERROR_STOP=1"], input=sql)
            port = command(["docker", "port", container, "5432/tcp"]).stdout.strip().split(":")[-1]
            env.update(AGENTOOLS_TEST_PG_DSN=f"pgsql:host=127.0.0.1;port={port};dbname={database}",
                       AGENTOOLS_TEST_PG_USER=owner, AGENTOOLS_TEST_PG_PASSWORD=password)
        # storage_chaos provides DSN preflight; this slice strengthens owner/marker verification.
        info = parse_dsn(env["AGENTOOLS_TEST_PG_DSN"])
        info.update(user=env.get("AGENTOOLS_TEST_PG_USER", info["user"]),
                    password=env.get("AGENTOOLS_TEST_PG_PASSWORD", info["password"]))
        info = preflight_dsn(php_dsn(info) + ";user=" + info["user"] + ";password=" + info["password"])
        report["isolation"] = verify(info)  # read-only, before PHP/schema mutation
        env.update(AGENTOOLS_TEST_PG_DSN=php_dsn(info), AGENTOOLS_TEST_PG_USER=info["user"],
                   AGENTOOLS_TEST_PG_PASSWORD=info["password"])
        with tempfile.TemporaryDirectory(prefix="agentools_p3_report_") as directory:
            junit = Path(directory) / "junit.xml"
            argv = ["php", str(PACKAGE / "vendor/bin/phpunit"), "-c", str(configuration), "--log-junit", str(junit)]
            if args.filter:
                argv += ["--filter", args.filter]
            process = subprocess.run(argv, cwd=PACKAGE, env=env, capture_output=True, text=True, timeout=90)
            sys.stderr.write(process.stdout + process.stderr)
            if junit.is_file():
                for case in ET.parse(junit).iter("testcase"):
                    status = ("skipped" if case.find("skipped") is not None else
                              "failed" if case.find("failure") is not None or case.find("error") is not None else "passed")
                    name = case.attrib["name"]
                    report["cases"].append({"id": (case_names or {}).get(name, case_prefix + "." + name), "status": status})
            report["isolation"]["case_ids"] = [c["id"] for c in report["cases"]]
            report.update(count=len(report["cases"]), failures=sum(c["status"] == "failed" for c in report["cases"]),
                          skips=sum(c["status"] == "skipped" for c in report["cases"]))
            return 0 if process.returncode == 0 and report["cases"] and report["failures"] == report["skips"] == 0 else 1
    except Exception as error:
        # Do not serialize credentials/DSNs or subprocess arguments in failure reports.
        report["error"] = type(error).__name__ + ": isolated PostgreSQL bootstrap/test failed"
        if isinstance(error, subprocess.CalledProcessError):
            sys.stderr.write(re.sub(r"[0-9a-f]{48}", "[secret]", error.stderr or ""))
        return 1
    finally:
        if container:
            label = command(["docker", "inspect", "--format", '{{index .Config.Labels "agentools.p3.isolated"}}', container]).stdout.strip()
            if label != token:
                raise RuntimeError("container cleanup ownership mismatch")
            command(["docker", "rm", "--force", container])
        print(json.dumps(report))


if __name__ == "__main__":
    sys.exit(main())
