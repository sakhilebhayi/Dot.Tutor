#!/usr/bin/env sh
# R-47 perf (DEVELOPMENT ONLY): run a command against the perf world with production-like drivers.
#   sh scripts/perf/env.sh php scripts/perf/pages.php --dups          (SQLite: storage/perf/world-perf.sqlite)
#   PERF_DB=pgsql PERF_PG=tutor_perf_x sh scripts/perf/env.sh ...      (PostgreSQL: an existing tutor_perf_* database)
cd "$(dirname "$0")/../.."
if [ "${PERF_DB:-sqlite}" = "pgsql" ]; then
  set -- env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE="${PERF_PG:?tutor_perf_* database}" DB_USERNAME="$(whoami)" DB_PASSWORD= "$@"
else
  mkdir -p storage/perf; [ -f storage/perf/world-perf.sqlite ] || touch storage/perf/world-perf.sqlite
  set -- env DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/perf/world-perf.sqlite" DB_JOURNAL_MODE="${DB_JOURNAL_MODE-wal}" DB_TRANSACTION_MODE="${DB_TRANSACTION_MODE-IMMEDIATE}" "$@"
fi
exec env APP_ENV=local APP_DEBUG=false SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database LOG_LEVEL=error "$@"
