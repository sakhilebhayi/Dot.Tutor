# Deploying Dot.Tutor

Target: `https://tutor.infodot.co.za`, on the shared cPanel host that also runs the other Dot apps (InfoDot, Dot.Agents and the rest). Everything goes through GitHub Actions, run by hand.

**Status:** the live site runs the pre-R-47 release through this pipeline (deploy green 2026-10-31). The R-47 changes to the pipeline (database restore point, `academic:sync`, static docroot sync, `tutor:preflight` gate, wider CI and smoke) were checked with `actionlint` and by running the host and cron scripts against a fake pre-R-47 host; they have not yet run against the real host. The owner runbook for after the first R-47 deploy is `docs/R47_OPERATIONS.md`.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push to `main`; called by `deploy.yml` | The release gate: PHPUnit on SQLite and PostgreSQL 16, the upgrade rehearsal on a populated pre-R-47 database (both engines), JS tests, the Vite build and manifest check, and a production-like signed-in smoke. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual from `main`, `confirm=adopt` once, then `confirm=deploy` (optional `skip_db_dump=skip`) | See "First deploy" and the header of the file. |
| `server-logs.yml` | manual, read-only | Tails the newest Laravel log and the last output of each cron line. |

## Prerequisites checklist

Do these in order. The pipeline updates a live install; it does not create the host, the database or the `.env`.

### Hosting

- [ ] **DNS**: a record for `tutor.infodot.co.za` pointing at the cPanel host.
- [ ] **cPanel subdomain** `tutor.infodot.co.za` with its document root (`~/tutor.infodot.co.za` by default) and an issued TLS certificate (AutoSSL), so `https://tutor.infodot.co.za` answers.
- [ ] **Layout like the other Dot apps**: the Laravel app in a folder under the document root, with the web server serving its `public/` the way the other apps' folders do. The pipeline finds the app as the one `artisan` within two levels of `~/tutor.infodot.co.za` (set the secret `DEPLOY_PATH` to skip the search). If the document root has its own `build/` folder the pipeline mirrors the new assets into it; if it has none it links one to `<app>/public/build`.
- [ ] **PHP 8.3 or newer** for the subdomain (`composer.json` requires `^8.3`; CI runs 8.4) with `mbstring`, `openssl`, `ctype`, `tokenizer`, `fileinfo`, `gd` (with JPEG, PNG and WebP), `dom`, `xml` and the database driver (`pdo_pgsql` or `pdo_sqlite`; plus `sqlite3` for the SQLite restore point), recommended `curl`, `intl` and OPcache; limits `upload_max_filesize` 21M or more, `post_max_size` above it, `memory_limit` 256M. The `php` found by a non-interactive SSH session must be that version. `php artisan tutor:preflight` checks all of it (FAIL for what breaks the site).
- [ ] On the host: `git`, `composer`, `rsync`, `tar`, `flock`, `crontab`, and `pg_dump` when the database is PostgreSQL (the deploy takes a restore point before every migration and stops, with the site untouched, if it cannot). There is **no Node** on the host, by design: the frontend is built on the runner and synced.
- [ ] **A deploy SSH key pair**: the public half authorised for the cPanel account, the private half stored as `DEPLOY_SSH_KEY`. `DEPLOY_KNOWN_HOSTS` is the host's `ssh-keyscan` line; confirm its fingerprint with the host provider rather than trusting the scan.

### Database

- [ ] A **PostgreSQL** database and role created in cPanel (a SQLite file also works; `docs/R47_OPERATIONS.md` section 6). Every deploy copies whichever the host `.env` names before it migrates (`~/dot-tutor-db-<mode>-<time>.dump` or `.sqlite`, newest three kept).

### The host `.env`

The pipeline requires `<app>/.env` to exist and never overwrites it. Create it from `.env.example` and set at least the values below. On every run the pipeline forces `APP_DEBUG=false` and `APP_ENV=production`, and warns about an `http://` `APP_URL`.

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | `base64:` plus 32 random bytes, once (`php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`) | Generate it **before** the first run, because no `vendor/` exists yet to run `key:generate`. Never change it afterwards: it signs everyone out and breaks anything encrypted. |
| `APP_URL` | `https://tutor.infodot.co.za` | `.env.example` carries `http://tutor.infodot.co.za`: the right host over plain http. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the cPanel database | See above. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (the `.env.example` values) | Their tables come from the migrations. The deploy clears caches with targeted commands, never `optimize:clear`, because that would empty the database cache store. |
| `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | the SMTP account used by the other Dot apps | `.env.example` says `MAIL_MAILER=log`, which silently writes every email to the log file. |
| `LOG_LEVEL` | `warning` | `.env.example` says `debug`. |
| `APP_URL`, `SESSION_SECURE_COOKIE` | `https://tutor.infodot.co.za`, `true` | `.env.example` says `http://localhost` (safe for CI). Payment returns and email links are built from `APP_URL`. |
| `BROADCAST_CONNECTION` | `log` | No Reverb server runs on this host (see "Known limits"). Nothing in this app broadcasts; `.env.example` says `log`. |
| `INFODOT_REDEEM_URL` | `https://infodot.co.za/api/ecosystem/redeem` | Where this app redeems the one-time sign-in token InfoDot hands over. |
| `INFODOT_PLATFORM_KEY` | `tutor` | This app's key in InfoDot's registry. |
| `INFODOT_SERVICE_SECRET` | a long random value, the **same** as `ECOSYSTEM_SECRET_TUTOR` in InfoDot's `.env` | Proves who is redeeming. Never commit it; never print it. Without it people cannot sign in through InfoDot. |
| `INFODOT_REDEEM_TIMEOUT`, `INFODOT_PROVISION_USERS` | `5`, `true` | `false` stops InfoDot creating accounts here; people who already have one still sign in. |
| `DOT_INFODOT_URL` and the other `DOT_*_URL` | at least `DOT_INFODOT_URL=https://infodot.co.za` | `config/ecosystem.php` defaults them to the dead `https://*.infodot.app` addresses; they are the targets of the platform-switcher links. |
| `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET` | optional | Dot.Notify events for lessons, payments and payouts (R-47 comms). Blank: none sent; the app sends its own email. |
| `ANALYTICS_INGEST_URL` | e.g. `https://analytics.infodot.co.za/api/ingest/snapshots` | The daily aggregate metrics snapshot (`tutor:publish-metrics`). Blank means nothing is sent. |
| `ANALYTICS_INGEST_SECRET` | the same value as `ANALYTICS_INGEST_SECRET_TUTOR` in Dot.Analytics' `.env` | Blank means nothing is sent. |
| `ANALYTICS_PLATFORM_KEY` | `tutor` | |
| R-47 providers: `PAYSTACK_*`, `MONEY_*`, `AI_ENABLED` + `ANTHROPIC_API_KEY`, `COMMS_VAPID_*`, `CLASSROOM_TURN_*` | blank or off at first | Every one is documented in `.env.example` and switched on in the order of `docs/R47_OPERATIONS.md` section 1 (Paystack test then live with `PAYSTACK_ALLOW_LIVE=true`, mail, AI, push, TURN). `ANTHROPIC_MODEL` in `config/services.php` is an older unused entry; the assistant reads `AI_MODEL`. |
| `TUTOR_CURRENCY`, `TUTOR_TIMEZONE`, `TUTOR_THROTTLE_BOOKINGS`, `TUTOR_THROTTLE_UPLOADS` | optional | Defaults `ZAR`, `Africa/Johannesburg`, `10`, `10`. |

### InfoDot (the sign-in hub)

- [ ] **`ECOSYSTEM_SECRET_TUTOR`** in InfoDot's `.env`, equal to `INFODOT_SERVICE_SECRET` above.
- [ ] **`'tutor'` in the `service_secrets` list** of InfoDot's `config/ecosystem.php`. Today the list does not name it, so InfoDot refuses this app's redeem call. InfoDot PR #32 ("Redeem: allow service secrets for the 14 remaining platforms") was open when this was written; check that it has been merged and deployed.
- [ ] **Mark the platform live**: set `'active' => true` for `'tutor'` in InfoDot's `config/ecosystem.php` (it is `false` today), but only once the app really answers, so no one is sent to a dead address. InfoDot's `DOT_TUTOR_URL` already defaults to `https://tutor.infodot.co.za`.
- [ ] `ECOSYSTEM_TRUSTED_DOMAINS` in InfoDot keeps its default `infodot.co.za`, which covers `tutor.infodot.co.za`.

### Repository secrets (Settings, Secrets and variables, Actions)

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | `tutor.infodot.co.za` |
| `DEPLOY_USER` | the cPanel account user (the one the other Dot apps deploy as) |
| `DEPLOY_SSH_KEY` | the private deploy key |
| `DEPLOY_KNOWN_HOSTS` | the host's `known_hosts` line |
| `DEPLOY_PATH` | optional: the absolute app folder, to skip the search for `artisan` |

## First deploy

1. Finish the checklist. A brand-new, empty subdomain needs a first copy of the code and a `.env` before the pipeline can take over: `git clone https://github.com/sakhilebhayi/Dot.Tutor.git` into a folder under the document root (the repository is public), then create `.env` as above. (An empty folder fails the run before maintenance mode, because there is no `artisan` or `.env` to work with.)
2. Run **Drift Report** and read it. For a fresh clone of `main` it says the host already matches.
3. Run **Deploy to Production** with `confirm=adopt`. It writes a rollback copy, makes the folder a checkout of `main` without deleting anything untracked (`.env`, `storage/`), installs dependencies, ships the build, forces the production settings, **lists** the pending migrations without running them, rebuilds the caches, installs the cron lines, and checks the site. Add `migrate=migrate` only on a database you do not mind migrating at once (a brand-new empty one is fine).
4. Take a `pg_dump`, read the pending-migrations list in the log, then run it again with `confirm=deploy`, which migrates.
   The deploy runs `php artisan academic:sync` after every migrate: the academic reference data (languages, curricula, levels, the subject catalogue) is inserted on the first R-47 run and left alone afterwards. Grant the first operator with `php artisan ops:grant you@example.com --role=admin` after your first InfoDot sign-in (`docs/R47_OPERATIONS.md`, step 2).
5. Check the log (see "Reading the log"), open `https://tutor.infodot.co.za/login` and sign in through InfoDot.
6. Only then mark the platform live in InfoDot.

Every release after that: **Deploy to Production** with `confirm=deploy` (`gh workflow run deploy.yml -f confirm=deploy`). The CI gate runs first, then the frontend is built, then the host steps run.

A failure **before** maintenance mode (the gate, the build, ssh, the upload, the rollback copy) leaves the live site on the previous release. A failure **after** it starts leaves the site **down on purpose**, in maintenance mode, with the rollback copy's name in the log: read the log, fix the cause and run the same workflow again; it brings the site back up itself. It is never brought back automatically, because the Dot.Agents pipeline's automatic rollback once fired after failures that never touched the server.

## Cron and queue lines

The deploy installs **2 lines** in the account's single crontab, each ending in the marker `# dot-tutor`. The crontab is shared by every Dot app, so the step follows the rules learned when concurrent deploys once wiped it: it takes the shared lock (`~/.dot-crontab.lock`, `flock`), reads the crontab with a retry because `crontab -l` can transiently answer empty (it never writes after an empty read), replaces only the lines carrying its own marker, reads the crontab back, and restores the previous one if another app's line count changed or its own count is wrong.

```
* * * * * cd <app folder> && exec <php> artisan schedule:run > storage/logs/cron-scheduler.log 2>&1 # dot-tutor
<0|1>-59/2 * * * * cd <app folder> && exec <php> artisan queue:work --stop-when-empty --tries=3 --timeout=60 --max-time=50 > storage/logs/cron-queue.log 2>&1 # dot-tutor
```

`<php>` is the absolute path of `php` on the host (cron has a minimal `PATH`). Each line keeps its last output in `storage/logs/cron-scheduler.log` or `storage/logs/cron-queue.log`.

`schedule:run` runs every task the app schedules (14 after R-47; `tutor:preflight` fails if one is missing): `comms:deliver` and `classroom:sweep` every minute; `money:settle`, `tutor:expire-marketplace-holds`, `comms:reminders` and `safety:escalate-incidents` every 5 minutes; `safety:expire-approvals` hourly; `tutor:expire-stale-sessions` 02:00, `money:reconcile --record` 02:30, `tutor:publish-metrics` 03:00 UTC, `ai:purge-interactions` 03:30 UTC, `comms:prune` 03:40 UTC, `safety:audit-verify` 04:00 UTC and `money:draft-payouts` 06:00. New scheduled commands need no workflow change. In cron-runner mode (`~/bin/dot-cron-runner`) the same two commands go to `~/.dot-cron.d/tutor.cron` instead.

**Queue worker, the cPanel way.** The account cannot run a daemon, so the worker is started by cron every minute, drains the queue and exits (`--stop-when-empty`, at most 50 seconds, then the next cron run starts a fresh one). That means a queued job normally runs within a minute, a job released for a retry waits for a later cron minute, new code is picked up on the next start without any `queue:restart`, and nothing is processed while the site is in maintenance mode. `QUEUE_CONNECTION=database`; the `jobs` and `failed_jobs` tables come from the migrations. Queued after R-47: Paystack webhook processing (`ProcessPaystackWebhook`; `money:settle` also verifies open checkouts with Paystack every 5 minutes, so a late worker delays a confirmation but does not lose it), notification delivery (`DeliverNotifications`) and the Analytics snapshot. The worker line runs every second minute on this app's parity, so a queued job waits at most about two minutes; after the cron step the deploy runs `queue:restart`.

First run: the account's line total grows by 2. Every deploy log ends this step with `== crontab: 2 line(s) for # dot-tutor installed, M other line(s) preserved`; **M must never shrink** between runs. Check by hand with `crontab -l | grep '# dot-tutor'`.

## Rollback

- Every run writes `~/dot-tutor-<mode>-<UTC time>.tgz` (the app folder without `vendor/`, `node_modules/`, logs and the build) and a database copy `~/dot-tutor-db-<mode>-<UTC time>.dump|.sqlite` before maintenance mode; the newest three of each are kept. To restore: stop writes (`php artisan down`), `tar xzf` it over the app folder, run `composer install --no-dev`, redeploy to rebuild the frontend, then `php artisan up`; the database restore is in `docs/R47_OPERATIONS.md` section 2.
- **Code**: the pipeline always deploys `origin/main`. To go back, revert the change on `main` and run `confirm=deploy`. The log prints `== Current release: <sha>` before and `== Deployed: <sha>` after.
- **Database**: migrations are not rolled back. Restore the release's database copy (above) or a daily backup (`scripts/ops/backup.sh`).
- On `adopt`, host-only commits survive on a local branch `host-before-adopt-<time>`, and host-only migration files are moved to `~/dot-tutor-quarantine-<time>`, never deleted.

## Reading the log

- The Actions run log shows the steps in order: the input and secret check, the CI gate, the build, "Locate the app", the staged upload, "Deploy the code", "Install the scheduler cron lines" and the smoke check. Lines starting `==` are the host script's progress: `== Rollback copy`, `== Current release`, `== Code`, `== Pending migrations`, `== Deployed`, `== crontab: 2 line(s) for # dot-tutor installed, M other line(s) preserved` (M must not shrink), `== Smoke check passed.` An `::error::` line names the cause and says whether the site is still up.
- **Server Logs** (`server-logs.yml`) prints the last 120 lines of the newest `storage/logs/laravel*.log` and the last 10 lines of each `storage/logs/cron-*.log`. It is a fixed command, never a shell.
- The smoke check fetches `/`, `/login`, `/up`; checks that the learner, tutor, guardian and operator pages redirect a guest to the login and that a guessed private file answers 403 or 404; checks the security headers, every built entry file, the fonts, the icon sprites and `/comms-sw.js` at the docroot root; and sends one harmless `POST /up` (a 405) to prove no debug page is served. `tutor:preflight` runs on the host before the site leaves maintenance mode; a FAIL keeps it down with the reason.

## Known limits

- **Never run `php artisan db:seed` in production**: `DatabaseSeeder` creates `test@example.com` with a known password, and the `Demo*` seeders refuse production. Reference data comes from `php artisan academic:sync`, which the deploy runs.
- **Reverb cannot run under shared cPanel** (a long-running WebSocket process). The pipeline never starts, restarts or reloads it. Nothing in Dot.Tutor broadcasts (`laravel/reverb` is installed but unused): the classroom, inbox and rooms poll short versioned feeds. Set `BROADCAST_CONNECTION=log`.
- The live smoke check is signed out. Signed-in pages are exercised by the CI signed-in smoke on a production-like boot, not on the live host.
- The first deploy after a long gap migrates everything pending in one go; read the pending list from the `adopt` run first.
- The rollback copy includes `storage/app` (uploads and other files) and `.env`; check the account's disk quota, three copies are kept.
- Daily backups and the restore drill are scripts the owner installs once (`docs/R47_OPERATIONS.md` section 2); the deploy does not install the backup cron line. No Dot.Brain guardian endpoint exists for this app.
- `INFODOT_*` redeem only works once InfoDot lists this platform (checklist above).
