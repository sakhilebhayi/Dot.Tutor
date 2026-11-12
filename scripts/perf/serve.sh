#!/usr/bin/env sh
# R-47 perf (DEVELOPMENT ONLY): the perf world behind PHP's built-in server with N forked workers (the shape of a
# PHP-FPM pool with pm=static), OPcache on, debug off, production session/cache/queue drivers.
#   sh scripts/perf/serve.sh <workers> <port>          (PERF_DB=pgsql PERF_PG=tutor_perf_x for PostgreSQL)
cd "$(dirname "$0")/../.."
WORKERS="${1:-4}"; PORT="${2:-8731}"
export PHP_CLI_SERVER_WORKERS="$WORKERS"
# Laravel's router script expects to run from public/ (as `artisan serve` does).
exec sh scripts/perf/env.sh sh -c "cd public && exec php -d opcache.enable_cli=1 -d opcache.enable=1 -S 127.0.0.1:$PORT ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
