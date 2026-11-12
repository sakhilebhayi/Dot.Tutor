<?php

namespace App\Console\Commands;

use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Classroom\Services\IceConfig;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Comms\Sms\SmsSender;
use App\Domain\Money\Paystack\PaystackConfig;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that this host and this .env can run Dot.Tutor, and says plainly what is wrong and what it costs.
 *
 * Statuses: PASS (fine), INFO (a fact worth knowing), OFF (an optional provider that is switched off, never an error),
 * WARN (works, but people will notice; usually an owner step), FAIL (the site, or a whole area of it, is broken).
 * FAIL is kept for what breaks the site: a missing PHP extension, no APP_KEY, a debug page in production, an unreachable
 * or unmigrated database, unwritable folders, a public private disk, missing built assets or fonts, a missing scheduled
 * task. Everything the owner still has to switch on (Paystack, mail, AI, push, TURN, operators) is a WARN or OFF.
 * Exit code 1 when anything FAILS (with --strict, when anything WARNS too). --json prints the rows.
 *
 * deploy.yml runs it on the host after the migrations, academic:sync and the caches, before the site leaves maintenance
 * mode: a FAIL keeps the site down with the reason. The PHP limits it reads are those of the CLI binary; the website's
 * PHP can differ (cPanel MultiPHP INI Editor), which the row says. The last row answers "Can a learner book a paid
 * lesson?" and names every blocker.
 */
class TutorPreflight extends Command
{
    public const PASS = 'PASS';

    public const INFO = 'INFO';

    public const OFF = 'OFF';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** composer.json "php": "^8.3". */
    private const MIN_PHP_ID = 80300;

    /** Every task the product schedules (routes/console.php and the domain providers). A missing one is a FAIL. */
    public const EXPECTED_SCHEDULE = [
        'tutor:expire-stale-sessions',
        'tutor:publish-metrics',
        'tutor:expire-marketplace-holds',
        'money:settle',
        'money:reconcile',
        'money:draft-payouts',
        'safety:escalate-incidents',
        'safety:expire-approvals',
        'safety:audit-verify',
        'comms:deliver',
        'comms:reminders',
        'comms:prune',
        'classroom:sweep',
        'ai:purge-interactions',
    ];

    /** The private disks every domain writes to: label => disk name. Comms and community store no files. */
    public const PRIVATE_DISKS = [
        'Safety (message attachments, screening evidence)' => 'safety',
        'Learning (courses, library, assessments)' => 'learning',
        'Marketplace (tutor photos, qualification evidence)' => 'local',
        'Classroom and lesson files' => 'lesson-resources',
    ];

    /** Static files the pages ask for by path (not through Vite). */
    public const STATIC_FILES = [
        'icons/td-sprite.svg',
        'classroom/icons.svg',
        'comms/push.js',
        'comms-sw.js',
        'robots.txt',
        'images/logo.png',
    ];

    protected $signature = 'tutor:preflight
        {--json : Print the results as JSON}
        {--strict : Exit 1 on warnings as well as failures}';

    protected $description = 'Check PHP, limits, folders, configuration, providers and reference data; exit 1 if anything required is wrong';

    /** @var list<array{status: string, area: string, check: string, detail: string}> */
    private array $results = [];

    /** @var list<string> what stops a learner from booking and paying for a lesson right now */
    private array $bookingBlockers = [];

    public function handle(): int
    {
        $this->results = [];
        $this->bookingBlockers = [];

        $this->php();
        $this->limits();
        $this->application();
        $databaseUp = $this->database();
        $this->storage();
        $this->frontend();
        $this->queueAndSchedule($databaseUp);
        $this->providers();

        if ($databaseUp) {
            $this->referenceData();
            $this->people();
            $this->tutors();
        } else {
            $this->bookingBlockers[] = 'the database is unreachable or not fully migrated';
        }

        $this->readiness();

        return $this->report();
    }

    // ------------------------------------------------------------------ PHP

    private function php(): void
    {
        if (PHP_VERSION_ID >= self::MIN_PHP_ID) {
            $this->add(self::PASS, 'PHP', 'Version', PHP_VERSION.' (8.3 or newer is required; CI runs 8.4)');
        } else {
            $this->add(self::FAIL, 'PHP', 'Version', PHP_VERSION.' is too old: composer.json needs PHP 8.3 or newer.');
        }

        [$required, $recommended] = self::extensions((string) config('database.default'));
        $missing = array_values(array_filter($required, fn (string $e) => ! extension_loaded($e)));
        $this->add(
            $missing === [] ? self::PASS : self::FAIL,
            'PHP',
            'Extensions',
            $missing === [] ? implode(', ', $required) : 'Missing: '.implode(', ', $missing).'. Enable them for this PHP version in cPanel (Select PHP Version, Extensions).',
        );

        $soft = array_values(array_filter($recommended, fn (string $e) => ! extension_loaded($e)));
        $this->add(
            $soft === [] ? self::PASS : self::WARN,
            'PHP',
            'Recommended extensions',
            $soft === [] ? implode(', ', $recommended) : 'Missing: '.implode(', ', $soft).' (curl: Paystack, push and Dot.Notify calls fall back to PHP streams, slower and with fewer TLS options; intl: locale-aware formatting).',
        );

        if (extension_loaded('gd')) {
            $info = gd_info();
            $lacking = array_keys(array_filter(['JPEG' => 'JPEG Support', 'PNG' => 'PNG Support', 'WebP' => 'WebP Support'], fn (string $key) => empty($info[$key])));
            $this->add(
                $lacking === [] ? self::PASS : self::WARN,
                'PHP',
                'GD formats',
                $lacking === []
                    ? 'JPEG, PNG and WebP: uploaded photos are decoded and re-encoded, which strips their location metadata.'
                    : 'GD lacks '.implode(', ', $lacking).' support: tutor photos, evidence and attachments in that format are refused with a message.',
            );
        }

        // Web Push signs with ECDSA P-256 (ES256) and encrypts with AES-128-GCM (RFC 8291): both come from OpenSSL.
        $curves = function_exists('openssl_get_curve_names') ? (openssl_get_curve_names() ?: []) : [];
        $ciphers = function_exists('openssl_get_cipher_methods') ? array_map('strtolower', openssl_get_cipher_methods()) : [];
        $pushReady = in_array('prime256v1', $curves, true) && in_array('aes-128-gcm', $ciphers, true);
        $this->add(
            $pushReady ? self::PASS : self::WARN,
            'PHP',
            'Web Push crypto',
            $pushReady ? 'OpenSSL has P-256 and AES-128-GCM' : 'OpenSSL lacks the P-256 curve or AES-128-GCM: push notifications cannot be signed or encrypted (in-app and email still work).',
        );

        $this->add(self::INFO, 'PHP', 'sodium', 'not needed: nothing in Dot.Tutor uses libsodium (Web Push uses OpenSSL).');

        $opcache = extension_loaded('Zend OPcache');
        $this->add($opcache ? self::PASS : self::WARN, 'PHP', 'OPcache', $opcache ? 'loaded' : 'not loaded: every request recompiles the code, and the classroom and inbox poll every few seconds. Enable it in cPanel.');
    }

    /**
     * @return array{0: list<string>, 1: list<string>} required (FAIL when missing) and recommended (WARN)
     */
    public static function extensions(string $driver): array
    {
        $required = ['mbstring', 'openssl', 'ctype', 'json', 'pdo', 'tokenizer', 'fileinfo', 'gd', 'dom', 'xml'];
        $db = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'mysql', 'mariadb' => 'pdo_mysql',
            default => null,
        };

        if ($db !== null) {
            $required[] = $db;
        }

        return [$required, ['curl', 'intl']];
    }

    private function limits(): void
    {
        $this->addAll(self::evaluateLimits([
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ], self::largestUploadBytes(), (int) config('learning.max_video_kb', 204800) * 1024));

        $this->add(self::INFO, 'PHP limits', 'Which PHP', 'These are the limits of the PHP running this command ('.PHP_SAPI.'). The website can run with different ones: compare them in cPanel, MultiPHP INI Editor.');
    }

    /** The largest non-video file the product accepts (course and library files, lesson files, evidence, attachments). */
    public static function largestUploadBytes(): int
    {
        return max(
            (int) config('learning.max_upload_kb', 20480),
            (int) config('classroom.files.max_kb', 10240),
            (int) config('safety.messaging.attachment_max_kb', 5120),
            (int) config('safety.screening.evidence_max_kb', 10240),
            (int) config('ai.resource.max_pdf_kb', 10240),
        ) * 1024;
    }

    /**
     * Pure: judges the limits it is given, so a test can feed it any host.
     *
     * @param  array<string, string>  $ini
     * @return list<array{status: string, area: string, check: string, detail: string}>
     */
    public static function evaluateLimits(array $ini, int $uploadBytes = 20 * 1048576, int $videoBytes = 200 * 1048576): array
    {
        $rows = [];
        $row = function (string $status, string $check, string $detail) use (&$rows): void {
            $rows[] = ['status' => $status, 'area' => 'PHP limits', 'check' => $check, 'detail' => $detail];
        };

        $upload = self::bytes($ini['upload_max_filesize'] ?? '2M');
        $post = self::bytes($ini['post_max_size'] ?? '8M');
        $memory = self::bytes($ini['memory_limit'] ?? '128M');
        $time = (int) ($ini['max_execution_time'] ?? 30);
        $needPost = $uploadBytes + 1048576;

        if ($upload !== null && $upload > 0 && $upload < $uploadBytes) {
            $row(self::WARN, 'upload_max_filesize', ($ini['upload_max_filesize'] ?? '').' is below the '.self::mb($uploadBytes).' a course or library file may be: larger files fail with a generic error. Set it to '.self::mb($needPost).'.');
        } elseif ($upload !== null && $upload > 0 && $upload < $videoBytes) {
            $row(self::INFO, 'upload_max_filesize', ($ini['upload_max_filesize'] ?? '').': documents fit; course videos up to '.self::mb($videoBytes).' (LEARNING_MAX_VIDEO_KB) do not. Raise it, or lower LEARNING_MAX_VIDEO_KB so the form says the true limit.');
        } else {
            $row(self::PASS, 'upload_max_filesize', ($upload === null || $upload === 0 ? 'unlimited' : (string) $ini['upload_max_filesize']).' (files up to '.self::mb($uploadBytes).', videos up to '.self::mb($videoBytes).')');
        }

        if ($post !== null && $post > 0 && $post < $needPost) {
            $row(self::WARN, 'post_max_size', ($ini['post_max_size'] ?? '').' is smaller than a full-size file plus its form ('.self::mb($needPost).' needed): the upload arrives empty.');
        } else {
            $row(self::PASS, 'post_max_size', $post === null || $post === 0 ? 'unlimited' : (string) $ini['post_max_size']);
        }

        if ($memory === null || $memory < 0) {
            $row(self::INFO, 'memory_limit', 'unlimited here; the website may still have a limit.');
        } elseif ($memory < 64 * 1048576) {
            $row(self::FAIL, 'memory_limit', ($ini['memory_limit'] ?? '').' is too low for the larger pages and any photo upload. Set it to 256M.');
        } elseif ($memory < 256 * 1048576) {
            $row(self::WARN, 'memory_limit', ($ini['memory_limit'] ?? '').': large camera photos (up to 40 megapixels are accepted) may run out of memory while they are re-encoded. Set 256M.');
        } else {
            $row(self::PASS, 'memory_limit', (string) $ini['memory_limit']);
        }

        $row($time === 0 || $time >= 30 ? self::PASS : self::WARN, 'max_execution_time', $time === 0 ? 'unlimited' : "{$time}s".($time < 30 ? ' is short: Paystack and the AI provider may take up to 10 and 60 s.' : ''));

        return $rows;
    }

    /** PHP shorthand ("8M", "2G", "512K", "-1") to bytes; null for unlimited (-1) or empty. */
    public static function bytes(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').' MB';
    }

    // ------------------------------------------------------------------ application

    private function application(): void
    {
        $production = app()->environment('production');

        $this->add($production ? self::PASS : self::INFO, 'Application', 'Environment', $production ? 'production' : config('app.env').'; the deploy forces production on the live host.');

        $this->add(
            filled(config('app.key')) ? self::PASS : self::FAIL,
            'Application',
            'APP_KEY',
            filled(config('app.key'))
                ? 'set. It encrypts sessions, hidden message originals and stored secrets: back it up with every database backup and never rotate it casually.'
                : 'missing: sessions, sign-in and every encrypted field fail. Generate it once and never change it.',
        );

        if (config('app.debug')) {
            $this->add($production ? self::FAIL : self::WARN, 'Application', 'APP_DEBUG', 'on: every error would show a stack trace and configuration to visitors.');
        } else {
            $this->add(self::PASS, 'Application', 'APP_DEBUG', 'off');
        }

        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        if ($local) {
            if ($production) {
                $this->bookingBlockers[] = 'APP_URL is a local address, so Paystack cannot send the learner back';
            }
            $this->add($production ? self::WARN : self::INFO, 'Application', 'APP_URL', ($url === '' ? 'empty' : $url).': a local address. Payment returns, emails, push links and calendar links would point nowhere. Set https://tutor.infodot.co.za.');
        } elseif ($scheme !== 'https') {
            $this->add($production ? self::WARN : self::INFO, 'Application', 'APP_URL', "{$url} is not https: browsers refuse push and camera on http, and Paystack needs an https callback.");
        } else {
            $this->add(self::PASS, 'Application', 'APP_URL', $url);
        }

        if ($scheme === 'https') {
            $secure = config('session.secure');
            $this->add($secure === true ? self::PASS : self::WARN, 'Application', 'Session cookie', $secure === true ? 'Secure flag on' : 'SESSION_SECURE_COOKIE is not true, so the session cookie is also sent over http. Set it to true.');
        }

        $hsts = (int) config('security.hsts_seconds', 0);
        $this->add($hsts > 0 ? self::PASS : self::INFO, 'Application', 'HSTS', $hsts > 0 ? "{$hsts} seconds on https responses" : 'off (SECURITY_HSTS_SECONDS=0)');
        $reportOnly = (bool) config('security.csp_report_only', true);
        $this->add(self::INFO, 'Application', 'Content-Security-Policy', $reportOnly ? 'report-only (SECURITY_CSP_REPORT_ONLY=true): violations are reported to /csp-report, nothing is blocked' : 'enforced');

        if ($production) {
            $cached = [
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
                'views' => (glob(config('view.compiled', storage_path('framework/views')).'/*.php') ?: []) !== [],
            ];
            $notCached = array_keys(array_filter($cached, fn (bool $v) => ! $v));
            $this->add(
                $notCached === [] ? self::PASS : self::WARN,
                'Application',
                'Caches',
                $notCached === [] ? 'config, routes, events and views are cached' : 'not cached: '.implode(', ', $notCached).' (run config:cache, route:cache, view:cache, event:cache)',
            );
        }

        $level = strtolower((string) config('logging.channels.'.config('logging.default').'.level', config('logging.channels.single.level', 'debug')));
        $this->add($production && $level === 'debug' ? self::WARN : self::PASS, 'Application', 'Log level', $level.($production && $level === 'debug' ? ': debug floods the log on a platform that polls. Set LOG_LEVEL=warning.' : ''));

        $mailer = (string) config('mail.default');
        $mailOff = in_array($mailer, ['log', 'array'], true);
        $this->add(
            $mailOff ? ($production ? self::WARN : self::INFO) : self::PASS,
            'Application',
            'Mail',
            $mailer.($mailOff ? ': nobody receives email. Guardian approval requests, receipts, lesson and safety emails are recorded as not delivered. Set a real SMTP transport (no-reply@infodot.co.za).' : ''),
        );

        $broadcast = (string) config('broadcasting.default');
        $this->add(
            $broadcast === 'reverb' ? ($production ? self::WARN : self::INFO) : self::PASS,
            'Application',
            'Broadcasting',
            $broadcast.($broadcast === 'reverb' ? ': no Reverb server can run on shared hosting. Nothing in Dot.Tutor broadcasts (screens poll), so set BROADCAST_CONNECTION=log to keep it that way.' : ' (screens poll short versioned feeds; no websocket is needed)'),
        );

        $open = in_array('registration', (array) config('fortify.features'), true);
        $this->add($open ? self::WARN : self::PASS, 'Application', 'Sign-up', $open ? 'public sign-up is OPEN (ALLOW_REGISTRATION=true): anyone can make an account without InfoDot' : 'closed; InfoDot creates accounts at first sign-in');
    }

    // ------------------------------------------------------------------ database

    private function database(): bool
    {
        $production = app()->environment('production');
        $driver = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            $this->add(self::PASS, 'Database', 'Connection', $driver.' at '.($driver === 'sqlite' ? 'a file' : (string) config("database.connections.{$driver}.host")));
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Connection', 'Cannot connect ('.class_basename($e).'). Check DB_* in .env.');

            return false;
        }

        if ($driver === 'sqlite') {
            $this->add($production ? self::WARN : self::INFO, 'Database', 'Driver', 'sqlite: one file, one writer at a time. Fine for a launch; the suites also run on PostgreSQL 16, the database to move to before real traffic (docs/R47_OPERATIONS.md, "Running on SQLite").');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $this->add($production ? self::WARN : self::INFO, 'Database', 'Driver', "{$driver}: the suites run on SQLite and PostgreSQL only.");
        } else {
            $this->add(self::PASS, 'Database', 'Driver', $driver);
        }

        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->add(self::FAIL, 'Database', 'Migrations', 'The migrations table does not exist. Run php artisan migrate --force.');

                return false;
            }

            $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
            $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

            $this->add(
                $pending === [] ? self::PASS : self::FAIL,
                'Database',
                'Migrations',
                $pending === [] ? count($files).' applied, none pending' : count($pending).' pending (first: '.$pending[0].'). Run php artisan migrate --force.',
            );

            return $pending === [];
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Migrations', 'Could not read the migration state ('.class_basename($e).').');

            return false;
        }
    }

    // ------------------------------------------------------------------ storage

    private function storage(): void
    {
        $dirs = [
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'storage/app' => storage_path('app'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $bad = array_keys(array_filter($dirs, fn (string $path) => ! is_dir($path) || ! is_writable($path)));
        $this->add($bad === [] ? self::PASS : self::FAIL, 'Storage', 'Writable folders', $bad === [] ? implode(', ', array_keys($dirs)) : 'Not writable or missing: '.implode(', ', $bad));

        foreach (self::PRIVATE_DISKS as $label => $disk) {
            $this->addAll([self::privateDiskRow($label, $disk, public_path())]);
        }

        $free = @disk_free_space(storage_path());

        if ($free !== false) {
            $this->add(
                $free < 100 * 1048576 ? self::FAIL : ($free < 1024 * 1048576 ? self::WARN : self::PASS),
                'Storage',
                'Free disk space',
                self::mb((int) $free).' free where storage/ lives (uploads, logs, rollback copies and backups all land on this account)',
            );
        }
    }

    /**
     * One disk: local, private, outside public/, writable. Public so a test can point it at a bad configuration.
     *
     * @return array{status: string, area: string, check: string, detail: string}
     */
    public static function privateDiskRow(string $label, string $diskName, string $publicPath): array
    {
        $row = fn (string $status, string $detail) => ['status' => $status, 'area' => 'Storage', 'check' => $label, 'detail' => $detail];
        $disk = config("filesystems.disks.{$diskName}");

        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
            return $row(self::FAIL, "disk '{$diskName}' is not a configured local disk: uploads in this area fail.");
        }

        $root = rtrim((string) ($disk['root'] ?? ''), '/');
        $rootReal = rtrim((string) (realpath($root) ?: $root), '/');
        $inPublic = false;

        foreach (array_unique(array_filter([rtrim($publicPath, '/'), rtrim((string) realpath($publicPath), '/')])) as $public) {
            if ($root !== '' && (str_starts_with($root.'/', $public.'/') || str_starts_with($rootReal.'/', $public.'/'))) {
                $inPublic = true;
            }
        }

        if ($root === '' || $inPublic || ($disk['visibility'] ?? 'private') === 'public' || ! empty($disk['url'])) {
            return $row(self::FAIL, "disk '{$diskName}' is reachable from the web (public visibility, a url, or a folder under public/). Learners' files, including children's, must stay private.");
        }

        $existing = $root;
        while ($existing !== '' && $existing !== '/' && ! is_dir($existing)) {
            $existing = dirname($existing);
        }

        if ($existing === '' || ! is_writable($existing)) {
            return $row(self::FAIL, "{$root} is not writable.");
        }

        $where = str_starts_with($root, base_path().'/') ? substr($root, strlen(base_path()) + 1) : $root;
        $served = ! empty($disk['serve']) ? '; Laravel serves it only to signed temporary URLs (a guessed /storage/... link answers 403)' : '';

        return $row(self::PASS, "private disk '{$diskName}', outside public/, ".(is_dir($root) ? 'writable' : 'created on the first upload')." ({$where}){$served}");
    }

    // ------------------------------------------------------------------ frontend

    private function frontend(): void
    {
        $this->addAll(self::frontendRows(public_path(), resource_path()));
    }

    /**
     * The built assets against every @vite entry in the views, the self-hosted fonts the stylesheets name, and the static
     * files pages ask for by path. Public so a test can point it at a broken folder.
     *
     * @return list<array{status: string, area: string, check: string, detail: string}>
     */
    public static function frontendRows(string $public, string $resources): array
    {
        $rows = [];
        $row = function (string $status, string $check, string $detail) use (&$rows): void {
            $rows[] = ['status' => $status, 'area' => 'Frontend', 'check' => $check, 'detail' => $detail];
        };

        $manifestPath = $public.'/build/manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

        if (! is_file($manifestPath)) {
            $row(self::FAIL, 'Built assets', 'public/build/manifest.json is missing: every page that loads @vite would answer 500.');
        } elseif (! is_array($manifest)) {
            $row(self::FAIL, 'Built assets', 'public/build/manifest.json is not valid JSON.');
        } else {
            $needed = self::viteEntriesInViews($resources.'/views');
            $missing = array_values(array_filter($needed, fn (string $e) => ! isset($manifest[$e])));
            $absent = [];

            foreach ($manifest as $entry) {
                foreach (array_merge([(string) ($entry['file'] ?? '')], (array) ($entry['css'] ?? [])) as $file) {
                    if ($file !== '' && ! is_file($public.'/build/'.$file)) {
                        $absent[] = $file;
                    }
                }
            }

            if ($missing !== []) {
                $row(self::FAIL, 'Built assets', 'Missing from the manifest: '.implode(', ', $missing));
            } elseif ($absent !== []) {
                $row(self::FAIL, 'Built assets', count($absent).' file(s) the manifest names are not in public/build, first: '.$absent[0]);
            } else {
                $row(self::PASS, 'Built assets', count($needed).' entries used by the views are all built');
            }
        }

        $fonts = [];
        foreach (is_dir($resources.'/css') ? File::allFiles($resources.'/css') : [] as $css) {
            if (preg_match_all('#/fonts/[A-Za-z0-9._/-]+\.woff2#', (string) file_get_contents($css->getPathname()), $m)) {
                foreach ($m[0] as $ref) {
                    $fonts[$ref] = true;
                }
            }
        }
        $fontsMissing = array_values(array_filter(array_keys($fonts), fn (string $ref) => ! is_file($public.$ref)));
        $row(
            $fonts !== [] && $fontsMissing === [] ? self::PASS : self::FAIL,
            'Self-hosted fonts',
            $fonts === [] ? 'The stylesheets reference no font under /fonts.' : ($fontsMissing === [] ? count($fonts).' font files referenced by the stylesheets are present' : 'Missing: '.implode(', ', $fontsMissing)),
        );

        $staticMissing = array_values(array_filter(self::STATIC_FILES, fn (string $f) => ! is_file($public.'/'.$f)));
        $row(
            $staticMissing === [] ? self::PASS : self::FAIL,
            'Icons and static files',
            $staticMissing === [] ? 'icon sprites, the push script and the service worker (/comms-sw.js, scope /) are present' : 'Missing from public/: '.implode(', ', $staticMissing),
        );

        return $rows;
    }

    /**
     * Every entry named in a literal @vite([...]) in the Blade views.
     *
     * @return list<string>
     */
    public static function viteEntriesInViews(?string $viewsPath = null): array
    {
        $entries = [];

        foreach (File::allFiles($viewsPath ?? resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all('/@vite\(\s*\[(.*?)\]\s*\)/s', (string) file_get_contents($view->getPathname()), $directives)) {
                foreach ($directives[1] as $list) {
                    if (preg_match_all('/[\'"](resources\/[^\'"]+)[\'"]/', $list, $m)) {
                        foreach ($m[1] as $entry) {
                            $entries[$entry] = true;
                        }
                    }
                }
            }
        }

        $entries = array_keys($entries);
        sort($entries);

        return $entries;
    }

    // ------------------------------------------------------------------ queue and schedule

    private function queueAndSchedule(bool $databaseUp): void
    {
        $production = app()->environment('production');
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add($production ? self::WARN : self::INFO, 'Queue', 'Queue driver', 'sync: Paystack webhooks, notification delivery and the Analytics snapshot run inside the request. Use database on the live host.');
        } elseif ($connection === 'database') {
            if (! $databaseUp || ! Schema::hasTable('jobs')) {
                $this->add($databaseUp ? self::FAIL : self::INFO, 'Queue', 'Queue driver', $databaseUp ? 'database, but the jobs table is missing.' : 'database (not read: the database is not ready).');
            } else {
                $waiting = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $age = $oldest ? time() - (int) $oldest : 0;
                $this->add(
                    $age > 900 ? self::WARN : self::PASS,
                    'Queue',
                    'Backlog',
                    $waiting === 0 ? 'database, nothing waiting' : "{$waiting} job(s) waiting, the oldest for ".intdiv($age, 60).' min'.($age > 900 ? ': the cron worker is not draining them (it does not run during maintenance mode).' : ''),
                );
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
                $this->add($failed > 0 ? self::WARN : self::PASS, 'Queue', 'Failed jobs', $failed > 0 ? "{$failed} failed job(s): php artisan queue:failed to read them, queue:retry all after fixing the cause." : 'none');
            }
        } else {
            $this->add(self::WARN, 'Queue', 'Queue driver', "{$connection}: this host has no Redis or SQS; use database.");
        }

        $this->add(self::INFO, 'Queue', 'Worker model', 'cron-driven: queue:work --stop-when-empty --tries=3 --max-time=50 every second minute (deploy.yml installs it; no daemon on cPanel). Queued: Paystack webhooks, notification delivery, the Analytics snapshot.');

        $scheduled = [];
        foreach (app(Schedule::class)->events() as $event) {
            $scheduled[] = (string) ($event->command ?? '');
        }
        $missing = array_values(array_filter(self::EXPECTED_SCHEDULE, fn (string $c) => ! collect($scheduled)->contains(fn (string $s) => preg_match('/(^|[\s\'"])'.preg_quote($c, '/').'([\s\'"]|$)/', $s) === 1)));
        $this->add(
            $missing === [] ? self::PASS : self::FAIL,
            'Schedule',
            'Registered tasks',
            $missing === [] ? count(self::EXPECTED_SCHEDULE).' tasks: notification delivery and the classroom sweep every minute; settlement, marketplace holds, reminders and incident escalation every 5 minutes; guardian approval expiry hourly; nightly reconciliation, retention purges, the audit chain check and payout drafts.' : 'Not scheduled: '.implode(', ', $missing),
        );

        $log = storage_path('logs/cron-scheduler.log');
        $seen = is_file($log) ? time() - (int) filemtime($log) : null;

        if ($seen !== null && $seen < 300) {
            $this->add(self::PASS, 'Schedule', 'Cron seen', 'schedule:run wrote its log '.intdiv($seen, 60).' min ago');
        } else {
            $this->add(
                $production ? self::WARN : self::INFO,
                'Schedule',
                'Cron seen',
                $seen === null
                    ? 'storage/logs/cron-scheduler.log does not exist yet. Without the every-minute scheduler, notifications are not sent, unpaid holds never expire and incidents never escalate. deploy.yml installs the line after this check (expected on a first deploy).'
                    : 'the scheduler log is '.intdiv($seen, 60).' min old: the cron line is not running (notifications stall, holds never expire, incidents never escalate).',
            );
        }
    }

    // ------------------------------------------------------------------ providers

    private function providers(): void
    {
        $production = app()->environment('production');

        // Payments: Paystack, test mode unless switched on twice (key AND PAYSTACK_ALLOW_LIVE=true).
        $paystack = app(PaystackConfig::class);
        $webhook = rtrim((string) config('app.url'), '/').'/api/tutor/money/webhooks/paystack';

        if (! $paystack->isConfigured()) {
            $this->bookingBlockers[] = 'no Paystack key, so no paid checkout';
            $this->add(self::WARN, 'Providers', 'Paystack', 'off: no PAYSTACK_SECRET_KEY. Free lessons and free courses work; every paid lesson, course, resource and room says online payment is not enabled.');
        } elseif (! $paystack->canTakePayments()) {
            $this->bookingBlockers[] = 'the Paystack key is refused';
            $this->add(self::WARN, 'Providers', 'Paystack', 'configured but refused: '.$paystack->notice());
        } elseif ($paystack->isTestMode()) {
            $this->add($production ? self::WARN : self::INFO, 'Providers', 'Paystack', "TEST mode (sk_test_): checkout works end to end, no real money moves. For launch set the live keys AND PAYSTACK_ALLOW_LIVE=true. Webhook URL in the Paystack dashboard: {$webhook}");
        } else {
            $this->add(self::PASS, 'Providers', 'Paystack', "LIVE mode (PAYSTACK_ALLOW_LIVE=true). The webhook URL in the Paystack dashboard must be {$webhook}");
        }

        $transfers = (bool) config('money.payouts.paystack_transfers');
        if (! $transfers) {
            $this->add(self::OFF, 'Providers', 'Payout transfers', 'manual: payout batches are drafted and exported as a bank file; finance pays them and marks them paid');
        } elseif (! $paystack->canTakePayments()) {
            $this->add(self::WARN, 'Providers', 'Payout transfers', 'MONEY_PAYSTACK_TRANSFERS=true but Paystack is not usable: every transfer would fail and stay for finance.');
        } else {
            $this->add($paystack->isTestMode() ? self::INFO : self::PASS, 'Providers', 'Payout transfers', 'Paystack Transfers on'.($paystack->isTestMode() ? ' (test mode: no real money)' : '').'; needs a Registered Business on Paystack with transfers enabled');
        }

        // AI assistant: off unless AI_ENABLED=true and a key is set.
        $ai = app(AiTutor::class);
        $enabled = (bool) config('ai.enabled');
        $budget = (int) config('ai.limits.monthly_budget_micro_usd');
        $spent = Schema::hasTable('ai_interactions') ? (int) DB::table('ai_interactions')->where('created_at', '>=', now()->startOfMonth())->sum('cost_micro_usd') : 0;
        $money = fn (int $micro) => '$'.number_format($micro / 1_000_000, 2);

        if (! $enabled) {
            $this->add(self::OFF, 'Providers', 'AI assistant', 'off (AI_ENABLED=false): the assistant pages say so; nothing is sent to any model.');
        } elseif (! $ai->configured()) {
            $this->add(self::WARN, 'Providers', 'AI assistant', 'AI_ENABLED=true but ANTHROPIC_API_KEY is empty: the assistant stays off and says so.');
        } else {
            $this->add(
                $budget > 0 && $spent >= $budget ? self::WARN : self::PASS,
                'Providers',
                'AI assistant',
                'Anthropic, model '.$ai->modelFor('standard').', monthly budget '.$money($budget).', spent this month '.$money($spent).($budget > 0 && $spent >= $budget ? ': the budget is used up, the assistant refuses until next month or a higher AI_MONTHLY_BUDGET_USD.' : ''),
            );
        }

        // Web Push (VAPID).
        $vapid = app(VapidKeys::class);
        if ($vapid->configured()) {
            $this->add(self::PASS, 'Providers', 'Web Push', 'VAPID keys set; changing them later makes every device switch push on again');
        } elseif (filled(config('comms.push.public_key')) || filled(config('comms.push.private_key'))) {
            $this->add(self::WARN, 'Providers', 'Web Push', 'COMMS_VAPID_PUBLIC_KEY / COMMS_VAPID_PRIVATE_KEY are set but not a valid pair: run php artisan comms:vapid-keys and paste its lines.');
        } else {
            $this->add(self::OFF, 'Providers', 'Web Push', 'off: no VAPID keys (php artisan comms:vapid-keys makes them). Notifications stay in-app and email.');
        }

        // SMS: only a null adapter exists today.
        $smsDriver = (string) config('comms.sms.driver');
        $sms = app(SmsSender::class);
        if ($smsDriver === '' || $smsDriver === 'null') {
            $this->add(self::OFF, 'Providers', 'SMS', 'off: no SMS adapter is configured; safety messages go in-app, by push and by email, and no screen claims an SMS was sent.');
        } elseif (! $sms->configured()) {
            $this->add(self::WARN, 'Providers', 'SMS', "COMMS_SMS_DRIVER={$smsDriver}, but no adapter for it is installed: nothing is sent by SMS.");
        } else {
            $this->add(self::PASS, 'Providers', 'SMS', $smsDriver);
        }

        // TURN relay for live lessons.
        $ice = app(IceConfig::class)->forUser(0);
        $turnUrls = (array) config('classroom.rtc.turn_urls', []);
        if ($ice['relay']) {
            $this->add(self::PASS, 'Providers', 'Classroom relay (TURN)', count($turnUrls).' TURN URL(s) with credentials; learners behind strict networks can still join');
        } elseif ($turnUrls !== []) {
            $this->add(self::WARN, 'Providers', 'Classroom relay (TURN)', 'CLASSROOM_TURN_URLS is set without CLASSROOM_TURN_SECRET or a username and credential: the relay is not offered.');
        } else {
            $this->add($production ? self::WARN : self::INFO, 'Providers', 'Classroom relay (TURN)', 'none: lessons connect peer to peer'.($ice['stun'] ? ' with STUN' : ' without STUN').'; on mobile data or school networks some cannot connect, and the classroom says so and offers the tutor\'s own meeting link.');
        }

        $sso = (string) config('ecosystem.infodot.secret', '');
        $this->add(
            filled($sso) ? self::PASS : ($production ? self::WARN : self::INFO),
            'Providers',
            'InfoDot sign-in',
            filled($sso) ? 'service secret set' : 'INFODOT_SERVICE_SECRET is empty: people cannot sign in through InfoDot, the only way in while sign-up is closed.',
        );

        $this->pair('Dot.Notify', ['NOTIFY_INGEST_URL' => config('notify.url'), 'NOTIFY_INGEST_SECRET' => config('notify.secret')], 'off: Dot.Tutor sends its own email; no Notify events are sent');
        $this->pair('Dot.Analytics', ['ANALYTICS_INGEST_URL' => config('tutor.analytics.url'), 'ANALYTICS_INGEST_SECRET' => config('tutor.analytics.secret')], 'off: no daily aggregate is sent');
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function pair(string $label, array $vars, string $offText): void
    {
        $set = array_filter($vars, fn ($v) => filled($v));

        if ($set === []) {
            $this->add(self::OFF, 'Providers', $label, $offText);
        } elseif (count($set) < count($vars)) {
            $this->add(self::WARN, 'Providers', $label, 'partly set: '.implode(' and ', array_keys(array_diff_key($vars, $set))).' is empty, so nothing is sent.');
        } else {
            $url = (string) reset($vars);
            $this->add(str_starts_with($url, 'https://') ? self::PASS : self::WARN, 'Providers', $label, 'on, '.parse_url($url, PHP_URL_HOST).(str_starts_with($url, 'https://') ? '' : ' (not https)'));
        }
    }

    // ------------------------------------------------------------------ data

    private function referenceData(): void
    {
        $tables = [
            'languages' => 'languages',
            'countries' => 'countries',
            'curricula' => 'curricula',
            'academic levels' => 'academic_levels',
            'subjects' => 'subjects',
            'subject offerings' => 'curriculum_subject_levels',
        ];
        $counts = [];

        foreach ($tables as $label => $table) {
            if (! Schema::hasTable($table)) {
                $this->add(self::FAIL, 'Reference data', 'Academic structure', "the {$table} table is missing; run the migrations.");
                $this->bookingBlockers[] = 'academic tables missing';

                return;
            }
            $counts[$label] = $table === 'subjects'
                ? DB::table('subjects')->whereNotNull('slug')->count()
                : DB::table($table)->where(fn ($q) => Schema::hasColumn($table, 'is_active') ? $q->where('is_active', true) : $q)->count();
        }

        $empty = array_keys(array_filter($counts, fn (int $n) => $n === 0));
        $summary = implode(', ', array_map(fn ($label, $n) => "{$n} {$label}", array_keys($counts), $counts));

        if ($empty !== []) {
            $this->bookingBlockers[] = 'no academic reference data (php artisan academic:sync)';
            $this->add(self::WARN, 'Reference data', 'Academic structure', 'missing: '.implode(', ', $empty).'. Learners cannot pick a curriculum, level or subject. Run php artisan academic:sync (the deploy runs it after migrate).');
        } else {
            $this->add(self::PASS, 'Reference data', 'Academic structure', $summary.' (academic:sync ran)');
        }

        if (Schema::hasTable('legacy_subject_level_map')) {
            $mapped = DB::table('legacy_subject_level_map')->distinct()->pluck('legacy_level')->all();
            $unmapped = DB::table('subjects')->whereNull('slug')->whereNull('canonical_subject_id')->count();
            $strays = DB::table('subjects')->whereNotIn('level', $mapped ?: [''])->distinct()->orderBy('level')->pluck('level')->all();
            $this->add(
                $unmapped === 0 && $strays === [] ? self::PASS : self::INFO,
                'Reference data',
                'Legacy subjects',
                ($unmapped === 0 ? 'every pre-R-47 subject is linked to or part of the catalogue' : "{$unmapped} subject row(s) not yet mapped (academic:sync maps them)")
                .($strays === [] ? '' : '; legacy level text with no academic mapping (rows kept as they are): '.implode(', ', $strays)),
            );
        }
    }

    private function people(): void
    {
        $production = app()->environment('production');
        $operators = DB::table('users')->where('is_platform_operator', true)->count();
        $roles = Schema::hasTable('ops_role_assignments') ? DB::table('ops_role_assignments')->count() : null;

        $this->add(
            $operators > 0 ? self::PASS : ($production ? self::WARN : self::INFO),
            'People',
            'Operators',
            $operators > 0
                ? "{$operators} platform operator(s)".($roles === null ? '' : ", {$roles} role assignment(s)")
                : 'none: nobody can approve tutors, verify qualifications, moderate reports, answer an incident or pay tutors. After your first InfoDot sign-in run php artisan ops:grant you@example.com --role=admin (docs/R47_OPERATIONS.md, step 2).',
        );

        $officers = $roles === null ? null : DB::table('ops_role_assignments')->whereIn('role', ['admin', 'safeguarding_officer'])->distinct()->count('user_id');
        $mailOff = in_array((string) config('mail.default'), ['log', 'array'], true);
        $who = $officers === null ? "{$operators} operator(s)" : "{$operators} operator(s), {$officers} of them admin or safeguarding officer";

        if ($operators === 0) {
            $this->add($production ? self::WARN : self::INFO, 'People', 'Safeguarding contacts', 'nobody: a report about a child or an incident alert reaches no one. Every platform operator receives incident alerts; grant at least one safeguarding officer.');
        } elseif ($officers === 0) {
            $this->add(self::WARN, 'People', 'Safeguarding contacts', "{$who}: incident alerts reach every operator, but no one holds the safeguarding_officer or admin role to act on them.");
        } elseif ($mailOff) {
            $this->add($production ? self::WARN : self::INFO, 'People', 'Safeguarding contacts', "{$who}; incident alerts are in-app and push only because mail is off: someone must watch /safety/operator/incidents.");
        } else {
            $this->add(self::PASS, 'People', 'Safeguarding contacts', "{$who} receive incident alerts in-app and by email; unacknowledged incidents escalate every 5 minutes");
        }
    }

    private function tutors(): void
    {
        $approved = DB::table('tutor_profiles')->where('status', 'approved');
        $approvedCount = (clone $approved)->count();
        $pending = DB::table('tutor_profiles')->where('status', 'pending')->count();
        $bookable = 0;

        if (Schema::hasTable('tutor_offerings')) {
            $bookable = (clone $approved)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('tutor_offerings')->whereColumn('tutor_offerings.tutor_profile_id', 'tutor_profiles.id')->where('is_active', true)->where('price_minor', '>', 0))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('tutor_availability_windows')->whereColumn('tutor_availability_windows.tutor_profile_id', 'tutor_profiles.id'))
                ->count();
        }

        if ($bookable === 0) {
            $this->bookingBlockers[] = 'no approved tutor with an active priced offering and open hours';
        }

        $this->add(
            $bookable > 0 ? self::PASS : self::INFO,
            'People',
            'Tutors',
            "{$approvedCount} approved, {$pending} waiting for review; {$bookable} approved with an active priced offering and open hours (bookable for a paid lesson)",
        );
    }

    private function readiness(): void
    {
        $this->add(
            $this->bookingBlockers === [] ? self::PASS : self::WARN,
            'Readiness',
            'Can a learner book a paid lesson?',
            $this->bookingBlockers === [] ? 'yes: reference data, a bookable tutor, a usable Paystack key and a reachable APP_URL are all in place' : 'NO: '.implode('; ', array_unique($this->bookingBlockers)).'.',
        );
    }

    // ------------------------------------------------------------------ output

    /** @param  list<array{status: string, area: string, check: string, detail: string}>  $rows */
    private function addAll(array $rows): void
    {
        foreach ($rows as $row) {
            $this->results[] = $row;
        }
    }

    private function add(string $status, string $area, string $check, string $detail): void
    {
        $this->results[] = ['status' => $status, 'area' => $area, 'check' => $check, 'detail' => $detail];
    }

    private function report(): int
    {
        $counts = array_fill_keys([self::PASS, self::INFO, self::OFF, self::WARN, self::FAIL], 0);

        foreach ($this->results as $r) {
            $counts[$r['status']]++;
        }

        $exit = ($counts[self::FAIL] > 0 || ($this->option('strict') && $counts[self::WARN] > 0)) ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $exit === self::SUCCESS, 'counts' => $counts, 'results' => $this->results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $area = null;

        foreach ($this->results as $r) {
            if ($r['area'] !== $area) {
                $area = $r['area'];
                $this->newLine();
                $this->line("<fg=white;options=bold>{$area}</>");
            }

            $colour = match ($r['status']) {
                self::PASS => 'green',
                self::FAIL => 'red',
                self::WARN => 'yellow',
                default => 'gray',
            };
            $this->line(sprintf('  <fg=%s>%-4s</>  %-34s %s', $colour, $r['status'], $r['check'], $r['detail']));
        }

        $this->newLine();
        $summary = sprintf('%d pass, %d info, %d off, %d warning(s), %d failure(s).', $counts[self::PASS], $counts[self::INFO], $counts[self::OFF], $counts[self::WARN], $counts[self::FAIL]);
        $exit === self::SUCCESS ? $this->info('Preflight OK: '.$summary) : $this->error('Preflight FAILED: '.$summary);

        return $exit;
    }
}
