# R-47 owner runbook: Dot.Tutor in production

For the person who owns the platform. Everything here is a command you run on the host (SSH into the cPanel account, `cd` to the app folder under `~/tutor.infodot.co.za`), a page on the site (`https://tutor.infodot.co.za`), or a setting in a `.env` (this app's or another Dot platform's). The pipeline and the host checklist are in `docs/deployment.md`; this file is what happens **after** a deploy and when something goes wrong. Decisions behind it: D-1900 onward in `docs/R47_DECISIONS.md`.

Two rules for every `.env` change below:

1. **The host runs from a cached configuration.** After editing `<app>/.env` run `php artisan config:cache` (or redeploy). The scheduler and the queue worker start a fresh process from cron, so they pick the change up once the cache is rebuilt.
2. **Check with `php artisan tutor:preflight`.** It reads the configuration the app really uses and prints PASS / INFO / OFF / WARN / FAIL with the fix. A provider that is switched off reads `OFF`, never an error; an owner step still to do reads `WARN`; `FAIL` is kept for what breaks the site (the deploy refuses to leave maintenance mode on a FAIL). Its last line, **Can a learner book a paid lesson?**, names everything that still stops a paid booking.

## 1. First steps after the first R-47 deploy

Do these once, in this order. Until steps 2 to 4 are done the site is up and safe, but **no learner can pay for a lesson**: that is the honest state, not a fault. Free lessons, free courses, the library, rooms and the classroom work from the first minute.

1. **Read the deploy log.** Look for `== Database restore point`, the `academic:sync` lines (languages, curricula, levels, subjects, offerings inserted on the first run, "nothing inserted" afterwards), `Preflight OK` and `== Smoke check passed.`, then run `php artisan tutor:preflight` yourself and read every WARN line. The academic sync also reports **legacy level text with no academic mapping** (old subjects whose level someone typed by hand): those rows are kept exactly as they were; see step 5.

2. **Become the operator.** Sign in once through InfoDot (that creates your account), then on the host:
   `php artisan ops:grant you@yourdomain.co.za --role=admin` (the ops agent's command; `--role` is one of admin, academic_editor, verification_reviewer, safeguarding_officer, content_moderator, support_agent, finance; `--add` keeps roles already held). Every grant is audited. Give each other operator the narrowest role that fits. If the release you deployed predates `ops:grant`, set the flag once with `php artisan tinker` (`App\Models\User::where('email', 'you@yourdomain.co.za')->update(['is_platform_operator' => true]);`) and grant roles when it lands. Preflight `People / Operators` must then PASS.

3. **Paystack: test first, then live.**
   - In the Paystack dashboard (Settings, API Keys and Webhooks) set the webhook URL to `https://tutor.infodot.co.za/api/tutor/money/webhooks/paystack`.
   - Put the **test** keys in the host `.env`: `PAYSTACK_SECRET_KEY=sk_test_...`, `PAYSTACK_PUBLIC_KEY=pk_test_...`. `config:cache`, preflight: `Paystack  WARN  TEST mode` (a warning in production on purpose: no real money moves).
   - Run one whole paid journey with test cards: a tutor with an offering and open hours, a learner books, the tutor accepts, the learner pays, the lesson happens in the classroom, the tutor completes it, the learner reviews, the tutor sees the earning (pending, then available after `MONEY_LESSON_HOLD_HOURS`), the payout batch drafts on the payout day. Do one minor's booking too: it waits for the guardian, then the payment waits for the guardian.
   - For real money set the live keys **and** `PAYSTACK_ALLOW_LIVE=true` (the second switch). A `sk_live_` key without it is refused: preflight says so and every checkout says online payment is switched off. Decide `MONEY_COMMISSION_PERCENT` (default 15), refund windows and the payout day first: they are your business settings, not market facts. Fill in `MONEY_OPERATOR_LEGAL_NAME`, `MONEY_OPERATOR_REGISTRATION`, `MONEY_OPERATOR_ADDRESS`, `MONEY_SUPPORT_EMAIL` (and VAT only if registered): receipts leave missing lines off rather than invent them.
   - Paystack Transfers for payouts stays off (`MONEY_PAYSTACK_TRANSFERS=false`): payout batches are exported as a bank file from `/money/ops` and marked paid. Switch transfers on only with a Registered Business on Paystack with transfers enabled.

4. **Mail.** `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS=no-reply@infodot.co.za` (the account the other Dot apps use). With `log`, guardian approval requests, receipts, lesson emails and safety alerts are written to the log and **nobody receives them**: a guardian request that nobody sees lapses after 72 hours. Send yourself a test: invite yourself as a guardian from a test learner account.

5. **Academic review.** `academic:sync` installed South Africa CAPS and IEB Grades 1 to 12, a tertiary track (undergraduate to doctoral, professional) and Cambridge as a second example, 15 languages, the subject catalogue, which subject is offered at which level, starter topics and the public universities. It never overwrites an edit and never deletes. In `/operator/academic`: switch off curricula or levels you do not serve yet, check the subject list, and look at the legacy subjects the sync linked or catalogued (an old "Mathematics" row was adopted as the catalogue Mathematics with its id kept, so every old booking still points at it). Legacy level text with no mapping (for example a hand-typed "Grade 10 (IEB)") is listed by preflight `Reference data / Legacy subjects`; the rows still work as before, and you can retire or rename them in `/operator/subjects`.

6. **Existing tutors: approved, but not bookable until they make an offering.** The upgrade keeps every tutor in the state they were (approved, pending, suspended), converts their hourly rate to cents exactly, and gives nobody a verification level: their free-text qualifications show as "Self-declared, not verified". New bookings go through **offerings** (one lesson, group, package, weekly lessons) with a price, a duration and a cancellation policy, so an approved tutor cannot be booked for a paid lesson until they create at least one offering and set open hours. Preflight `People / Tutors` counts how many are bookable. Tell your tutors (an email from you is best), then work the verification queue in `/operator/verification`: verify or reject qualification evidence, then assign a verification level. A tutor who teaches minors also needs a screening record (child protection register and sexual offences register, checked off-platform, recorded in `/safety/operator/screening`; a second operator confirms each one while `SAFETY_SCREENING_FOUR_EYES=true`).

7. **Safeguarding contacts.** Every platform operator receives incident alerts (in-app, by push when VAPID is set, by email when mail is real); an unacknowledged incident escalates every 5 minutes up to level 3. Make sure at least one person holds `safeguarding_officer` (or admin), that their email is real, and that someone looks at `/safety/operator/incidents` every working day. Preflight `People / Safeguarding contacts` says who is reached and how. Fill in the escalation table in section 4 and keep it printed.

8. **AI assistant (optional).** Off until you decide. To switch on: an Anthropic API key with a spending limit set in the Anthropic console, then `AI_ENABLED=true`, `ANTHROPIC_API_KEY=...`, `AI_MONTHLY_BUDGET_USD` (the app refuses further requests once the month's spend reaches it; preflight shows the spend), optionally `AI_MODEL` / `AI_MODEL_LIGHT`. Learners see answers labelled as AI guidance with age-band guardrails; every interaction is logged for review in `/ai/operator/log` and purged after `AI_LOG_RETENTION_DAYS`.

9. **Web Push.** `php artisan comms:vapid-keys` prints the lines; paste them into the host `.env`, `config:cache`. Never change them later (every device would have to switch push on again). Preflight `Providers / Web Push  PASS`. iPhones receive push only when the site is added to the home screen; the preferences page says so.

10. **TURN relay for the live classroom.** Without one, lessons connect peer to peer through STUN; on mobile data and many school networks some learners cannot connect, and the classroom says so and offers the tutor's own meeting link. A TURN server cannot run on shared hosting: rent one (a managed TURN service, or coturn on a small VPS) and set `CLASSROOM_TURN_URLS` (comma list, e.g. `turn:turn.example.co.za:3478,turns:turn.example.co.za:5349`) with either `CLASSROOM_TURN_SECRET` (coturn `use-auth-secret`; the app hands out time-limited credentials) or `CLASSROOM_TURN_USERNAME` / `CLASSROOM_TURN_CREDENTIAL`. Preflight `Classroom relay (TURN)  PASS`.

11. **Ecosystem links.** InfoDot: `INFODOT_SERVICE_SECRET` equal to `ECOSYSTEM_SECRET_TUTOR` in InfoDot's `.env`, and `tutor` marked live in InfoDot's registry (until then nobody can sign in: public sign-up is closed). Optional: Dot.Notify (`NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`; Notify must know the `tutor.lesson.*`, `tutor.payment.received` and `tutor.payout.paid` event names), Dot.Analytics (`ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`). Also set `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true` and `BROADCAST_CONNECTION=log` if the host `.env` still says otherwise (preflight warns about each).

12. **Backups, on day one** (section 2), the **restore drill** once now and then monthly, and an uptime monitor on `/up` (section 3).

13. **Last check.** `php artisan tutor:preflight` shows `Can a learner book a paid lesson?  PASS`. Then do one complete journey with real phones, test keys first: a learner finds a tutor in their language, books, pays, attends in the classroom, reviews; the tutor sees the earning; a guardian approves a child's booking.

## 2. Backups and the restore drill

What must survive: the **database**, **`storage/app/private`** (lesson files, course and library files, message attachments, screening evidence, tutor photos and qualification evidence), and **`APP_KEY`** with the `.env`. Without the key, sessions, hidden message originals and two-factor secrets cannot be decrypted. Keep a copy of the `.env` off the host (a password manager), never in the backup folder alone. The code is in git; the build is rebuilt by every deploy.

**What the pipeline does:** every deploy takes a copy of the files (`~/dot-tutor-<mode>-<time>.tgz`) and of the database (`~/dot-tutor-db-<mode>-<time>.dump` for PostgreSQL, `.sqlite` for SQLite: an online copy checked with `PRAGMA integrity_check`) **before** maintenance mode, keeping the newest three of each. That is a restore point per release, not a backup schedule.

**Daily backup.** `scripts/ops/backup.sh` writes `db-<UTC>.dump` (PostgreSQL, verified readable with `pg_restore --list`) or `db-<UTC>.sqlite` (online backup, integrity-checked), `files-<UTC>.tar.gz` (verified readable) and a `.sha256` beside each into `~/dot-tutor-backups` (mode 700), keeps the newest 14 of each, and exits non-zero with the reason on any failure. Run it once by hand:

```
cd ~/tutor.infodot.co.za/<app folder> && scripts/ops/backup.sh
```

Then add its cron line. **The marker must be `# tutor-backup`**: the deploy's crontab step replaces every line containing `# dot-tutor`, so a backup line marked `# dot-tutor-backup` would be wiped by the next deploy. The crontab is shared by every Dot app; write it under the same lock the deploys use (or, if the account runs the cron runner, put the command without the schedule in `~/.dot-cron.d/tutor-backup.cron`):

```
( flock 9; crontab -l > ~/.crontab.before-tutor-backup && { cat ~/.crontab.before-tutor-backup; echo '17 2 * * * cd ~/tutor.infodot.co.za/<app folder> && exec scripts/ops/backup.sh >> storage/logs/backup.log 2>&1 # tutor-backup'; } | crontab - ) 9>~/.dot-crontab.lock
crontab -l | grep -c .      # exactly one more line than before
```

`crontab -l` has been seen answering empty for a moment: if `~/.crontab.before-tutor-backup` is empty and you know the crontab is not, stop and do not write. 02:17 sits away from the other apps' minute-zero jobs and before this app's 02:30 reconciliation.

- **Off the account.** A backup on the same account does not survive losing the account. Set `BACKUP_OFFSITE=user@otherhost:/path/` in the cron line to rsync the folder out after each run, or fetch `~/dot-tutor-backups` from your own machine weekly.
- **Disk.** The script refuses to run with under 500 MB free; preflight warns under 1 GB. Lower the count with `BACKUP_KEEP=7` if needed. If `LEARNING_DISK_ROOT` points outside `storage/app/private`, the backup says those files are NOT covered.
- **POPIA.** Backups contain children's personal information and messages: the folder is mode 700, and erased data stays in backups until they age out (14 days by default). Say so in the privacy notice.

**Restore drill, monthly.** `scripts/ops/restore-drill.sh` restores the newest database backup into a scratch database (PostgreSQL) or scratch file (SQLite), never the live one, unpacks the newest files archive into a temp folder, checks the checksums, that every product table exists, the row counts beside the live ones, and that every lesson file and library file older than the archive has its file, then prints the seconds it took (your real recovery time). Shared hosts often refuse `CREATE DATABASE`: create an empty database in cPanel, grant it to the same role, pass `--target-db NAME`. Verified on a fake host for both engines (PASS), and it fails, as it should, when a lesson file is missing from the archive.

### Restore for real

1. `php artisan down`.
2. Choose the copy: `ls -lt ~/dot-tutor-backups` (or `~/dot-tutor-db-deploy-*` for a bad release). Verify it from inside its folder: `sha256sum -c db-<stamp>.<ext>.sha256`.
3. PostgreSQL: `pg_restore --clean --if-exists --no-owner --exit-on-error -h <DB_HOST> -U <DB_USERNAME> -d <DB_DATABASE> db-<stamp>.dump`. SQLite: copy `db-<stamp>.sqlite` over the `DB_DATABASE` file and delete its `-wal` and `-shm` files. Everything written after the backup is lost (payments included: reconcile against the Paystack dashboard afterwards, section 4).
4. Files, only if they were lost: `tar xzf files-<stamp>.tar.gz -C storage/app`.
5. The same `APP_KEY` as when the backup was taken. `php artisan config:cache && php artisan up`, then `php artisan tutor:preflight` and `php artisan money:reconcile`.

Never run `php artisan migrate:rollback` on the live database: rolling back the R-47 migrations drops every new table (orders, ledger, safeguarding records, courses) with its data.

## 3. Monitoring and thresholds

The host has no monitoring stack. These are the signals that exist, with the threshold that means act.

| Signal | How | Act when |
|---|---|---|
| Uptime | an external monitor on `https://tutor.infodot.co.za/up` (200 = the app boots and the database answers) | 2 misses in a row. A failed deploy leaves maintenance mode on purpose (503): read the run log. |
| Scheduler | `storage/logs/cron-scheduler.log` is rewritten every minute; preflight `Schedule / Cron seen` | older than 2 min: notifications stop, unpaid holds never expire, incidents never escalate, payouts never draft. `crontab -l \| grep -c '# dot-tutor'` must be 2 (0 in cron-runner mode, with `~/.dot-cron.d/tutor.cron` present). |
| Queue | `php artisan queue:failed`; preflight `Queue / Backlog` | a job older than 15 min, or any failed job: Paystack webhooks or notifications are not leaving (`queue:retry all` after fixing the cause). `money:settle` also verifies open checkouts with Paystack every 5 minutes, so a missed webhook is caught. |
| Safeguarding | `/safety/operator/incidents` and `/safety/operator/queue` (reports by priority with deadlines) | any critical incident not acknowledged within 60 min (it escalates by itself); any report past its deadline (critical 1 h, high 4 h, normal 24 h) |
| Guardian requests | `/safety/guardian` for families; approvals expire after 72 h (`safety:expire-approvals`) | families telling you a request never arrived: check mail first |
| Money | `/money/ops` (finance issues, refunds due, disputes); `money:reconcile --record` runs 02:30 daily | any open finance issue (`paid_item_not_fulfilled`, `refund_due`, a reconciliation mismatch); a refund due older than 2 working days |
| Payouts | `/money/ops`: a batch is drafted on `MONEY_PAYOUT_DAY` at 06:00 | a draft not exported and paid within 2 working days; tutors asking where their money is |
| Tutor verification | `/operator/verification`, `/operator/tutor-profiles` | applications or evidence waiting over 3 days (tutors cannot earn) |
| Moderation | `/rooms/moderation`, `/learning/library/moderation` | anything held for review over 24 h |
| AI | `/ai/operator/log` (flagged answers, month spend against the budget) | any flagged or reported answer; spend over 80 % of `AI_MONTHLY_BUDGET_USD` |
| Audit chain | `safety:audit-verify` runs 04:00 UTC; `storage/logs/laravel.log` | any `audit chain broken` line: someone changed or removed audit rows; treat it as a security incident |
| Errors | `storage/logs/laravel.log` (with `LOG_LEVEL=warning` it holds real problems only); Server Logs workflow | any `production.ERROR`: send it with the time to the developer |
| Backups | last line of `storage/logs/backup.log`; monthly drill | not today's `backup ok` |
| Disk | preflight `Storage / Free disk space` | under 1 GB: prune old rollback copies (`~/dot-tutor-*.tgz`) first |
| CSP | `grep csp.report storage/logs/laravel*.log` while the policy is report-only | violations from real pages: send them to the developer before the policy is enforced |

Weekly: `php artisan tutor:preflight --strict` (exits 1 on any warning; useful as a scheduled check once every owner step is done).

## 4. Incidents and escalation

Fill in this table and keep a printed copy with the operations team. Nothing in the app can do this for you.

| Role | Name | Phone | Email |
|---|---|---|---|
| Platform owner (final decisions, money, legal) | | | |
| Safeguarding lead (first call for anything about a child) | | | |
| Second safeguarding officer | | | |
| Developer on call (outages, deploys) | | | |
| Hosting provider support (cPanel account) | | | |
| Paystack support (payments, payouts) | | | |
| Legal adviser (POPIA, child protection) | | | |

- **A child at risk.** If anyone is in immediate danger: SAPS 10111, or 112 from a mobile; Childline South Africa 116 (free). In the app: open or acknowledge the incident in `/safety/operator/incidents` (acknowledging takes ownership and stops the escalation timer), suspend the account involved from the report queue, record every action on the incident timeline, record an outside referral (for example a SAPS case number) with "refer". Nothing is sent outside the platform by the app. South African law obliges anyone who knows of a sexual offence against a child to report it to the police (Criminal Law (Sexual Offences) Act, section 54): get legal advice the same day, and keep the evidence (the unredacted message originals are kept encrypted and shown on the operator report view).
- **The site is down.** Check `/up` and the last deploy run. In maintenance mode after a failed deploy: the log's `::error::` line names the cause (often a `tutor:preflight` FAIL that names the setting); fix it and re-run the workflow (it brings the site up itself), or restore (section 5). Not a deploy: hosting provider status, disk, PHP errors in the log.
- **Nobody can pay.** `php artisan tutor:preflight`: the `Can a learner book a paid lesson?` line names the missing piece (Paystack key refused or off, no bookable tutor, APP_URL wrong). Then the Paystack dashboard for an outage.
- **Payments wrong** (double charge, money taken but the lesson not confirmed): Paystack dashboard first, then `/money/ops` (a `paid_item_not_fulfilled` finance issue is raised automatically when a payment cannot be matched to its item). Refunds go through `/money/ops`, never directly in Paystack, or the ledger and the tutor's earnings will not match. `php artisan money:reconcile` re-checks everything.
- **Personal data exposed** (a leaked file, a child's details shown to a stranger): stop the exposure (take the item down, suspend the account), record what was exposed, to whom and when. POPIA section 22 requires notifying the Information Regulator and the people affected as soon as reasonably possible; children's information is special personal information. Get legal advice the same day.
- **AI said something harmful.** Flag it in `/ai/operator/log`, switch the assistant off at once if needed (`AI_ENABLED=false`, `config:cache`), and treat anything involving a child as a safeguarding incident.

## 5. Rollback

**A bad release (the site is up but wrong).** Prefer a forward fix: revert the change on `main` and run the deploy again (the log prints the release before and after). The R-47 migrations are additive: they add tables and columns and backfill cents beside the old decimal columns, which stay for history.

**A failed release (the site is in maintenance mode).** Read the `::error::` line: usually `tutor:preflight` FAIL (it names the setting), a migration error, or a missing PHP extension. Fix and re-run; or restore the previous release: stop writes, `tar xzf ~/dot-tutor-deploy-<time>.tgz` over the app folder, `composer install --no-dev`, re-deploy the previous commit to rebuild the frontend, `php artisan up`. The database stays as migrated unless you restore it (section 2, with the release's `~/dot-tutor-db-deploy-<time>.*`); everything written since is then lost.

**Going back to the pre-R-47 app** is a restore, not a rollback: restore the database copy the first R-47 deploy took (`~/dot-tutor-db-deploy-<time of that run>.*`) and deploy the pre-R-47 commit. Everything written since (orders, safeguarding records, courses) is lost, so do it only in the first hours.

## 6. Running on SQLite

The suites run on SQLite and PostgreSQL 16, and the upgrade rehearsal runs on both. If the host's `.env` says `DB_CONNECTION=sqlite`, it works and the deploy, backups and drill all handle it, but there is one writer at a time while the classroom, inbox and rooms poll. `CACHE_STORE=file` keeps rate-limiter writes off the database file. Move to PostgreSQL before real traffic: create the database in cPanel, point `DB_*` at it, deploy (the migrations build the schema), then copy the data across with a one-off export planned with the developer. There is no automated SQLite-to-PostgreSQL converter in this repository.

## 7. Routine

| When | What |
|---|---|
| After any `.env` edit | `php artisan config:cache`, `php artisan tutor:preflight` |
| Daily | `/safety/operator/incidents` and the report queue; the verification queue; `/money/ops` finance issues |
| Weekly | export and pay the payout batch in `/money/ops`, mark it paid; `tutor:preflight --strict`; failed jobs; moderation queues; the AI log |
| Monthly | restore drill; provider bills (AI, TURN) against expectations; prune old rollback copies in `~`; review academic structure and legacy subjects |
| After a curriculum change | edit in `/operator/academic` (the sync never overwrites your edits) |

## 8. The pipeline, and how it was verified (deploy agent, 2026-11-12)

| Piece | File | Verified |
|---|---|---|
| Readiness check | `app/Console/Commands/TutorPreflight.php` (`tutor:preflight [--json] [--strict]`) | 13 feature tests; output read on a fresh install (1 FAIL: no build; blockers named), a synced install, the upgraded rehearsal databases and a fake host |
| Vite manifest check | `scripts/ops/check-vite-manifest.mjs` | node tests; found and fixed a `resources/js/...` path in the head partial's comment |
| Upgrade rehearsal | `scripts/ops/upgrade-rehearsal.sh` + `.php` | PASS on SQLite and PostgreSQL 16 (local), 60+ checks each |
| Signed-in smoke | `tests/Support/signed-in-smoke.sh` + `smoke-seed.php` | PASS, 96 checks; found that `/classroom` was shadowed by the `public/classroom/` folder on Apache (fixed, D-1903) |
| Backup and drill | `scripts/ops/backup.sh`, `scripts/ops/restore-drill.sh` | PASS on a fake host, SQLite and PostgreSQL; the drill fails when a lesson file is missing |
| CI | `.github/workflows/ci.yml` | `actionlint` (with shellcheck) clean; each job's script run locally; not yet run on GitHub |
| Deploy | `.github/workflows/deploy.yml` | `actionlint` clean; the host script and the cron script extracted and run against a fake pre-R-47 host (git checkout of 2aa8daf with legacy rows, a real `build/` copy and a stale `robots.txt` in the docroot, other apps' cron lines): deploy, a second idempotent deploy, a `tutor:preflight` FAIL that kept the site in maintenance mode, the fix and a deploy that brought it back. Not run against the real host (no deploy was started). |
