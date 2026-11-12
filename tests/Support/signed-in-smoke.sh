#!/usr/bin/env bash
# Production-like signed-in smoke test for Dot.Tutor.
#
#   tests/Support/signed-in-smoke.sh            (needs vendor/ and a built frontend: composer install, npm run build)
#
# Boots the app the way the live host runs it: APP_ENV=production, APP_DEBUG=false, cached config / routes / events /
# views, the BUILT assets, a throw-away SQLite database migrated and filled by academic:sync, the PHP built-in server in
# front of public/. Signs in over HTTP (CSRF and all) as a learner, a tutor, a guardian and an operator, and requests
# their key pages, asserting the status AND a landmark in the body. Also checks the signed-out surface (landing, login,
# health, guest redirects, a guessed private file), the security headers, the fonts, icons, service worker and every
# built CSS/JS file, that a learner cannot open operator pages, and that the application log gained no ERROR line: a
# page that answers 200 after swallowing an exception is still broken. tutor:preflight must report no FAIL.
#
# Why: the PHP suite fakes Vite and never caches routes; outages that only a signed-in, cached, built app shows (a
# limiter registered in a route file, a view naming an asset that was not built) slipped past tests on other Dot apps.
#
# Everything lives under one temp folder (database, storage, caches) via LARAVEL_STORAGE_PATH and APP_*_CACHE, so the
# working tree, bootstrap/cache and your .env are untouched. Shell environment variables win over .env.
# Optional: PORT (default 8994), PHP (default php), KEEP=1 (keep the temp folder and print its path).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 2
PHP="${PHP:-php}"
PORT="${PORT:-8994}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-tutor-smoke.XXXXXX")"
SERVER_PID=""
FAILS=0
CHECKS=0

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
  if [ "${KEEP:-0}" = "1" ]; then echo "kept: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

die() { echo "SMOKE SETUP FAILED: $*" >&2; [ -f "$WORK/setup.log" ] && tail -30 "$WORK/setup.log" >&2; exit 2; }
# Random strings come from PHP, never from `tr ... </dev/urandom | head`: under pipefail that pipe dies of SIGPIPE.
rand_hex() { $PHP -r 'echo bin2hex(random_bytes((int) $argv[1]));' "$1"; }

[ -f public/build/manifest.json ] || die "public/build/manifest.json is missing: run npm run build first."
[ -f vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php ] || die "vendor/ is missing: run composer install."
command -v curl >/dev/null || die "curl is required."

# ---- the production-like environment --------------------------------------------------------------------------------
mkdir -p "$WORK/storage/app/private" "$WORK/storage/framework/cache/data" "$WORK/storage/framework/sessions" \
         "$WORK/storage/framework/views" "$WORK/storage/logs" "$WORK/cache"
: > "$WORK/smoke.sqlite"
export APP_ENV=production APP_DEBUG=false APP_URL="$BASE" APP_NAME="Dot.Tutor"
APP_KEY="base64:$($PHP -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY
export LARAVEL_STORAGE_PATH="$WORK/storage" LEARNING_DISK_ROOT="$WORK/storage/app/private/learning"
export APP_CONFIG_CACHE="$WORK/cache/config.php" APP_ROUTES_CACHE="$WORK/cache/routes-v7.php" \
       APP_EVENTS_CACHE="$WORK/cache/events.php" APP_SERVICES_CACHE="$WORK/cache/services.php" \
       APP_PACKAGES_CACHE="$WORK/cache/packages.php" VIEW_COMPILED_PATH="$WORK/storage/framework/views"
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite" DB_FOREIGN_KEYS=true DB_URL=''
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=log BROADCAST_CONNECTION=log
export LOG_CHANNEL=single LOG_LEVEL=warning SESSION_SECURE_COOKIE=false
export INFODOT_SERVICE_SECRET='' ALLOW_REGISTRATION=false NOTIFY_INGEST_URL='' ANALYTICS_INGEST_URL=''
export PAYSTACK_SECRET_KEY='' PAYSTACK_PUBLIC_KEY='' AI_ENABLED=false ANTHROPIC_API_KEY='' COMMS_SMS_DRIVER=''
export COMMS_VAPID_PUBLIC_KEY='' COMMS_VAPID_PRIVATE_KEY='' CLASSROOM_TURN_URLS=''
SMOKE_PASSWORD="$(rand_hex 16)"
export SMOKE_PASSWORD SMOKE_SEED=1

step() { echo "== $*"; }
run() { "$@" >> "$WORK/setup.log" 2>&1 || die "'$*' failed"; }

step "preparing a throw-away database and caches ($WORK)"
run $PHP artisan migrate --force
run $PHP artisan academic:sync
$PHP tests/Support/smoke-seed.php > "$WORK/seed.json" 2>> "$WORK/setup.log" || die "seeding the smoke people failed"
run $PHP artisan config:cache
run $PHP artisan route:cache
run $PHP artisan view:cache
run $PHP artisan event:cache

seed() { $PHP -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d[$argv[2]] ?? "";' "$WORK/seed.json" "$1"; }

step "tutor:preflight (warnings are expected on a laptop; failures are not)"
$PHP artisan tutor:preflight --json > "$WORK/preflight.json" 2>> "$WORK/setup.log"
BADPRE="$($PHP -r '$d=json_decode(file_get_contents($argv[1]),true); if(!is_array($d)){ echo "  preflight printed no JSON\n"; exit; } foreach($d["results"] as $r){ if($r["status"]==="FAIL"){ echo "  FAIL ".$r["area"]."/".$r["check"].": ".$r["detail"]."\n"; } }' "$WORK/preflight.json")"
[ -z "$BADPRE" ] || { echo "$BADPRE"; die "tutor:preflight failed on a production-like boot"; }
$PHP -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["results"] as $r){ if($r["area"]==="Readiness"){ echo "   ".$r["status"]." ".$r["check"]." ".$r["detail"]."\n"; } }' "$WORK/preflight.json"

step "starting the server on $BASE"
curl -s -o /dev/null --max-time 1 "$BASE/up" && die "something already answers on $BASE; set PORT to a free port."
( cd public && exec $PHP -S "127.0.0.1:$PORT" "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" ) > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null --max-time 2 "$BASE/up" && break
  kill -0 "$SERVER_PID" 2>/dev/null || die "the server stopped: $(tail -5 "$WORK/server.log")"
  sleep 0.2
done

# ---- assertion helpers ----------------------------------------------------------------------------------------------
JAR="$WORK/guest.jar"
BODY="$WORK/body.out"
HEAD="$WORK/head.out"

pass() { CHECKS=$((CHECKS + 1)); printf '  ok    %s\n' "$1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILS=$((FAILS + 1)); printf '  FAIL  %s\n' "$1"; }
title() { grep -o '<title>[^<]*' "$BODY" | head -1 | sed 's/<title>//'; }

# get PATH EXPECTED_STATUS LANDMARK_REGEX [curl args...]  (current cookie jar; an empty LANDMARK skips the body check)
get() {
  local path="$1" want="$2" landmark="$3"; shift 3
  local code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' "$@" "$BASE$path")
  if [ "$code" != "$want" ]; then fail "GET $path answered $code, expected $want ($(title))"; return 1; fi
  if [ -n "$landmark" ] && ! grep -Eqi -- "$landmark" "$BODY"; then fail "GET $path answered $want but the body lacks /$landmark/ (title: $(title))"; return 1; fi
  if grep -qiE 'Whoops|stack trace|vendor/laravel/framework' "$BODY"; then fail "GET $path shows a debug page"; return 1; fi
  pass "GET $path $code"
}
page() { get "$1" 200 "${2:-<main}"; }
json() { get "$1" 200 "${2:-^[[:space:]]*[\{\[]}" -H "Accept: application/json"; }
redirects_to() {
  local path="$1" target="$2" code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o /dev/null -w '%{http_code}' "$BASE$path")
  if [ "$code" = "302" ] && grep -qiE "^location: *[^ ]*$target" "$HEAD"; then pass "GET $path redirects to $target"; else fail "GET $path answered $code, expected a redirect to $target ($(grep -i '^location:' "$HEAD" | tr -d '\r'))"; fi
}
refused() {
  local path="$1" code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}' "$BASE$path")
  case "$code" in 403|404) pass "GET $path refused ($code)" ;; *) fail "GET $path answered $code, expected 403 or 404" ;; esac
}

sign_in() {
  local who="$1" email token code
  email="$(seed "$who")"
  JAR="$WORK/$who.jar"
  : > "$JAR"
  get "/login" 200 'name="_token"' >/dev/null || { fail "no login form for $who"; return 1; }
  token="$(grep -o 'name="_token"[^>]*value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//')"
  [ -n "$token" ] || token="$(grep -o 'value="[^"]*"[^>]*name="_token"' "$BODY" | head -1 | sed 's/^value="//; s/".*//')"
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' -X POST \
    --data-urlencode "_token=$token" --data-urlencode "email=$email" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/login")
  if [ "$code" = "302" ] && ! grep -qi '^location:.*/login' "$HEAD"; then
    pass "$who signed in ($(grep -i '^location:' "$HEAD" | tr -d '\r' | sed 's/^[Ll]ocation: *//'))"
  else
    fail "$who could not sign in (POST /login answered $code)"; return 1
  fi
}

# ---- 1. public surface, signed out ----------------------------------------------------------------------------------
step "signed out"
get "/up" 200 ""
get "/" 200 "<html"
get "/login" 200 'name="_token"'
get "/cookies" 200 "<html"
for P in /learn /dashboard /my/bookings /tutor/profile /tutor/availability /money/earnings /safety/guardian /operator/tutor-profiles /safety/operator/queue /money/ops; do
  redirects_to "$P" "/login"
done
# A guessed private file: never served. /storage/... is Laravel's signed-only route for the private disk.
for P in "/storage/lesson-resources/$(rand_hex 12).pdf" "/storage/safety/$(rand_hex 12).png" "/storage/app/private/learning/x.pdf" "/lesson-resources/$(rand_hex 12).pdf"; do
  refused "$P"
done
code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' -X POST "$BASE/up")
if grep -qiE 'vendor/laravel|stack trace' "$BODY"; then fail "POST /up shows a debug page"; else pass "POST /up answers $code without a debug page"; fi
curl -s -D "$HEAD" -o /dev/null "$BASE/login"
for H in x-content-type-options x-frame-options referrer-policy permissions-policy "content-security-policy(-report-only)?"; do
  grep -Eqi "^$H:" "$HEAD" && pass "header $H on /login" || fail "header $H missing on /login"
done

# ---- 2. learner -----------------------------------------------------------------------------------------------------
step "learner"
if sign_in learner; then
  for P in /learn /learn/courses /learn/progress /learn/purchases /learn/account /learn/profile /my/bookings /find-a-tutor /tutors /learning/library /learning/courses /rooms /classroom /safety/me /language /ai; do
    page "$P" "<html"
  done
  page "/t/$(seed tutor_profile_id)" "Thandeka"
  redirects_to "/learn/find" "/find-a-tutor"
  json "/comms/inbox/summary"
  refused "/operator/tutor-profiles"
  refused "/safety/operator/queue"
  refused "/money/ops"
fi

# ---- 3. tutor -------------------------------------------------------------------------------------------------------
step "tutor"
if sign_in tutor; then
  for P in /teach /teach/profile /teach/calendar /my/bookings /money/earnings /learning/studio/courses /teach/rooms /classroom /ai/tutor /safety/me; do
    page "$P" "<html"
  done
  # The legacy profile and hours pages forward to the workspace (R-47 D-2201).
  redirects_to "/tutor/profile" "/teach/profile"
  redirects_to "/tutor/availability" "/teach/calendar"
  json "/api/tutor/me/offerings" 'One Maths lesson'
  refused "/safety/operator/incidents"
fi

# ---- 4. guardian ----------------------------------------------------------------------------------------------------
step "guardian"
if sign_in guardian; then
  page "/safety/guardian" "Lerato"
  json "/safety/guardian" 'Lerato'
  refused "/operator/verification"
fi

# ---- 5. operator ----------------------------------------------------------------------------------------------------
step "operator"
if sign_in operator; then
  for P in /operator/tutor-profiles /operator/verification /operator/subjects /operator/academic /safety/operator/queue /safety/operator/incidents \
           /ops/payments /rooms/moderation /ai/operator/log; do
    page "$P" "<html"
  done
  # The old finance desk forwards to the operations console (R-47 fix D-2400).
  redirects_to "/money/ops" "/ops/payments"
  page "/ops/payments?tab=exceptions" "Exceptions and unmatched payments"
  # Operator queues that have no screen of their own yet answer JSON.
  for P in /safety/operator/screening /safety/operator/integrity /safety/operator/audit /learning/library/moderation; do
    json "$P"
  done
fi

# ---- 6. fonts, icons, service worker and built assets (what the docroot must serve) ---------------------------------
step "fonts, icons, service worker and built assets"
JAR="$WORK/guest.jar"
for F in $(cd public && ls fonts/*.woff2); do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/$F")
  [ "$code" = "200" ] && [ "$(head -c4 "$BODY")" = "wOF2" ] && pass "/$F is a woff2 font" || fail "/$F answered $code or is not a woff2 file"
done
for F in icons/td-sprite.svg icons/classroom.svg; do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/$F")
  [ "$code" = "200" ] && grep -q '<svg' "$BODY" && pass "/$F is an SVG sprite" || fail "/$F answered $code or is not an SVG"
done
code=$(curl -s --max-time 30 -D "$HEAD" -o "$BODY" -w '%{http_code}' "$BASE/comms-sw.js")
[ "$code" = "200" ] && grep -qiE '^content-type: *(application|text)/javascript' "$HEAD" && pass "/comms-sw.js is served from the site root as JavaScript (scope /)" || fail "/comms-sw.js answered $code, $(grep -i '^content-type:' "$HEAD" | tr -d '\r')"
ASSETS="$($PHP -r '$m=json_decode(file_get_contents("public/build/manifest.json"),true); $f=[]; foreach($m as $v){ if(!empty($v["isEntry"])){ $f[$v["file"]]=1; foreach($v["css"]??[] as $c){ $f[$c]=1; } } } ksort($f); echo implode(" ", array_keys($f));')"
for A in $ASSETS; do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/build/$A")
  [ "$code" = "200" ] && [ -s "$BODY" ] && pass "/build/$A $code" || fail "/build/$A answered $code"
done
for P in .env storage/logs/laravel.log database/database.sqlite; do
  code=$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' "$BASE/$P")
  [ "$code" != "200" ] && pass "/$P not served ($code)" || fail "/$P answered 200"
done

# ---- 7. nothing was logged as an error ------------------------------------------------------------------------------
step "application log"
if grep -hE '^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' "$WORK"/storage/logs/*.log 2>/dev/null > "$WORK/errors.txt" && [ -s "$WORK/errors.txt" ]; then
  fail "the application logged errors during the run:"
  cut -c1-240 "$WORK/errors.txt" | head -10 | sed 's/^/        /'
else
  pass "no ERROR or worse in the application log"
fi
grep -qE 'Fatal error|Uncaught' "$WORK/server.log" && fail "the PHP server log has a fatal error: $(grep -E 'Fatal error|Uncaught' "$WORK/server.log" | head -2)" || pass "no PHP fatal in the server log"

echo
if [ "$FAILS" -eq 0 ]; then
  echo "SIGNED-IN SMOKE PASSED: $CHECKS checks."
  exit 0
fi
echo "SIGNED-IN SMOKE FAILED: $FAILS of $CHECKS checks."
exit 1
