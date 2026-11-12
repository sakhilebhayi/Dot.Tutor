#!/usr/bin/env bash
# Upgrade rehearsal: proves the R-47 migrations and academic:sync are safe on a POPULATED pre-R-47 database, not only
# on an empty one (the live tutor.infodot.co.za database has real tutors, lessons and reviews).
#
#   scripts/ops/upgrade-rehearsal.sh             on a throw-away SQLite file
#   scripts/ops/upgrade-rehearsal.sh --pgsql     on a throw-away PostgreSQL database (PGHOST/PGPORT/PGUSER/PGPASSWORD;
#                                                the role must be allowed to CREATE DATABASE)
#
# 1. Takes the migrations exactly as the pre-R-47 commit had them (`git archive`), not as this branch has them, and
#    builds that schema. The commit is REHEARSAL_BASE, or the pinned pre-R-47 main commit below, which is what
#    `git merge-base origin/main HEAD` answers for every R-47 branch (after R-47 merges, merge-base is R-47 itself, so
#    it is pinned rather than computed; D-1905). The legacy migration files must be byte-identical on this branch:
#    the live host has already run them.
# 2. Fills it with rows the way the old app wrote them (scripts/ops/upgrade-rehearsal.php seed): tutors in every state
#    with decimal rates, subjects with free-text levels (duplicates, odd case, a level string nothing maps to, one the
#    catalogue does not know, a switched-off one), lessons in every old status, reviews, lesson files on the private
#    disk, availability, blocked dates, teams, a two-factor secret, a notification.
# 3. Runs `php artisan migrate --force` and `php artisan academic:sync`, exactly as deploy.yml runs them, then
#    verifies (upgrade-rehearsal.php verify): no row lost or changed, minor-unit money companions exact, booking v2
#    defaults, the rating rule kept, subjects adopted or linked with no id or level string changed, the trunk's
#    models, directory and files read every legacy row, the new tables exist.
# 4. A second migrate is a no-op, a second academic:sync inserts nothing, and tutor:preflight reads the upgraded
#    database (Migrations and Academic structure PASS).
#
# Never touches a database it did not create. Exit 0 only when every check passed.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
PHP="${PHP:-php}"
DRIVER=sqlite
[ "${1:-}" = "--pgsql" ] && DRIVER=pgsql
# main before R-47: "Security headers: enforced baseline + report-only CSP (#26)".
BASE="${REHEARSAL_BASE:-2aa8dafd00ee16389d45d10dd11eb14ba3443d92}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-tutor-rehearsal.XXXXXX")"
PGDB=""

cleanup() {
  if [ -n "$PGDB" ]; then dropdb -h "${PGHOST:-127.0.0.1}" -p "${PGPORT:-5432}" -U "${PGUSER:-$(whoami)}" --if-exists "$PGDB" || echo "could not drop $PGDB; drop it by hand" >&2; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

git cat-file -e "$BASE^{commit}" 2>/dev/null || { echo "UPGRADE REHEARSAL FAILED: commit $BASE is not in this clone (CI: actions/checkout with fetch-depth: 0)"; exit 1; }
git merge-base --is-ancestor "$BASE" HEAD || { echo "UPGRADE REHEARSAL FAILED: $BASE is not an ancestor of HEAD"; exit 1; }

mkdir -p "$WORK/base" "$WORK/storage/framework/cache/data" "$WORK/storage/framework/sessions" "$WORK/storage/framework/views" \
         "$WORK/storage/logs" "$WORK/storage/app/private" "$WORK/cache"
git archive "$BASE" database/migrations | tar -x -C "$WORK/base"
BASE_MIGRATIONS="$WORK/base/database/migrations"

# The host already ran these files: a legacy migration edited on this branch would leave upgraded hosts and fresh
# installs with different schemas.
CHANGED=""
for f in "$BASE_MIGRATIONS"/*.php; do
  cmp -s "$f" "database/migrations/$(basename "$f")" || CHANGED="$CHANGED $(basename "$f")"
done
[ -z "$CHANGED" ] || { echo "UPGRADE REHEARSAL FAILED: pre-R-47 migration(s) differ on this branch:$CHANGED"; exit 1; }

APP_KEY="base64:$($PHP -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY APP_ENV=production APP_DEBUG=false APP_URL=https://tutor-rehearsal.example.org REHEARSAL=1
export LARAVEL_STORAGE_PATH="$WORK/storage"
export APP_CONFIG_CACHE="$WORK/cache/config.php" APP_ROUTES_CACHE="$WORK/cache/routes-v7.php" \
       APP_EVENTS_CACHE="$WORK/cache/events.php" APP_SERVICES_CACHE="$WORK/cache/services.php" \
       APP_PACKAGES_CACHE="$WORK/cache/packages.php" VIEW_COMPILED_PATH="$WORK/storage/framework/views"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=log \
       LOG_CHANNEL=single DB_URL='' AI_ENABLED=false ANTHROPIC_API_KEY='' PAYSTACK_SECRET_KEY='' \
       LEARNING_DISK_ROOT="$WORK/storage/app/private/learning"

if [ "$DRIVER" = "pgsql" ]; then
  PGDB="tutor_rehearsal_$($PHP -r 'echo bin2hex(random_bytes(5));')"
  createdb -h "${PGHOST:-127.0.0.1}" -p "${PGPORT:-5432}" -U "${PGUSER:-$(whoami)}" "$PGDB"
  export DB_CONNECTION=pgsql DB_HOST="${PGHOST:-127.0.0.1}" DB_PORT="${PGPORT:-5432}" DB_DATABASE="$PGDB" DB_USERNAME="${PGUSER:-$(whoami)}" DB_PASSWORD="${PGPASSWORD:-}"
  echo "== throw-away PostgreSQL database $PGDB"
else
  : > "$WORK/rehearsal.sqlite"
  export DB_CONNECTION=sqlite DB_DATABASE="$WORK/rehearsal.sqlite" DB_FOREIGN_KEYS=true
  echo "== throw-away SQLite file $WORK/rehearsal.sqlite"
fi

echo "== 1. the pre-R-47 schema: $(ls "$BASE_MIGRATIONS" | wc -l | tr -d ' ') migrations from $(git log -1 --format='%h %s' "$BASE")"
$PHP artisan migrate --force --realpath --path="$BASE_MIGRATIONS" > "$WORK/baseline.log" 2>&1 || { tail -20 "$WORK/baseline.log"; echo "UPGRADE REHEARSAL FAILED: the pre-R-47 schema did not build"; exit 1; }

echo "== 2. legacy rows"
$PHP scripts/ops/upgrade-rehearsal.php seed > "$WORK/seed.json" || { cat "$WORK/seed.json"; echo "UPGRADE REHEARSAL FAILED: seeding the legacy rows failed"; exit 1; }
$PHP -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["counts"] as $t=>$n){ printf("   %-28s %d\n", $t, $n); }' "$WORK/seed.json"

echo "== 3. php artisan migrate --force, then php artisan academic:sync (as deploy.yml runs them)"
PENDING="$($PHP artisan migrate:status --pending 2>/dev/null | grep -c 'Pending' || true)"
START=$(date +%s)
$PHP artisan migrate --force > "$WORK/migrate.log" 2>&1 || { cat "$WORK/migrate.log"; echo "UPGRADE REHEARSAL FAILED: migrate failed on the populated database"; exit 1; }
echo "   $PENDING migration(s) ran in $(( $(date +%s) - START )) s"
$PHP artisan academic:sync > "$WORK/sync.log" 2>&1 || { cat "$WORK/sync.log"; echo "UPGRADE REHEARSAL FAILED: academic:sync failed"; exit 1; }
sed 's/^/   /' "$WORK/sync.log"

echo "== 4. verify"
$PHP scripts/ops/upgrade-rehearsal.php verify < "$WORK/seed.json"

echo "== 5. a second migrate and a second academic:sync change nothing"
if $PHP artisan migrate --force 2>&1 | grep -q 'Nothing to migrate'; then echo "  ok    second migrate: nothing to migrate"; else echo "  FAIL  a second migrate did something"; exit 1; fi
if $PHP artisan academic:sync | grep -q 'nothing inserted'; then echo "  ok    second academic:sync: nothing inserted"; else echo "  FAIL  a second academic:sync inserted rows"; exit 1; fi

echo "== 6. tutor:preflight on the upgraded database"
$PHP artisan tutor:preflight --json > "$WORK/preflight.json" || true
$PHP -r '
  $d = json_decode(file_get_contents($argv[1]), true);
  $rows = [];
  foreach ($d["results"] as $r) { $rows[$r["area"]."/".$r["check"]] = $r; }
  foreach (["Database/Migrations", "Reference data/Academic structure", "Reference data/Legacy subjects", "People/Operators", "People/Tutors", "Readiness/Can a learner book a paid lesson?"] as $k) {
    if (isset($rows[$k])) { printf("   %-4s %s: %s\n", $rows[$k]["status"], $k, $rows[$k]["detail"]); }
  }
  exit(($rows["Database/Migrations"]["status"] ?? "") === "PASS" && ($rows["Reference data/Academic structure"]["status"] ?? "") === "PASS" ? 0 : 1);
' "$WORK/preflight.json" || { echo "  FAIL  preflight does not see a fully migrated and synced database"; exit 1; }

echo
echo "UPGRADE REHEARSAL ($DRIVER) PASSED."
