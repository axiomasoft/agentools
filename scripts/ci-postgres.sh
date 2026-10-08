#!/usr/bin/env bash
# Creates an isolated, short-lived cluster owned by this CI job only.
set -euo pipefail
: "${GITHUB_ENV:?This bootstrap is for a disposable GitHub Actions runner}"
: "${RUNNER_TEMP:?Missing runner temporary directory}"
pg_bin="$(find /usr/lib/postgresql -path '*/bin/initdb' -type f | sort -V | tail -1)"
[ -n "$pg_bin" ] || { echo 'PostgreSQL server binaries are required' >&2; exit 1; }
pg_bin="$(dirname "$pg_bin")"
pg_root="$(mktemp -d "$RUNNER_TEMP/agentools-pg-XXXXXX")"
"$pg_bin/initdb" -D "$pg_root/data" --auth=trust --no-locale > "$pg_root/init.log"
"$pg_bin/pg_ctl" -D "$pg_root/data" -l "$pg_root/server.log" -o "-h 127.0.0.1 -p 55432 -k $pg_root" -w start
"$pg_bin/psql" -h 127.0.0.1 -p 55432 -d postgres -v ON_ERROR_STOP=1 -c "CREATE ROLE agentools LOGIN PASSWORD 'agentools-test'"
"$pg_bin/createdb" -h 127.0.0.1 -p 55432 -O agentools agentools_ci_test
{
  echo 'AGENTOOLS_TEST_PG_DSN=pgsql:host=127.0.0.1;port=55432;dbname=agentools_ci_test'
  echo 'AGENTOOLS_TEST_PG_USER=agentools'
  echo 'AGENTOOLS_TEST_PG_PASSWORD=agentools-test'
  echo 'AGENTOOLS_REQUIRE_PG=1'
  echo 'AGENTOOLS_TEST_PG_REQUIRED=1'
} >> "$GITHUB_ENV"
