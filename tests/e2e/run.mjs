// Orchestrator for the browser end-to-end suite (docs/R47_QA.md). Adapted from Dot.Ehail's R-46 harness. It builds the
// assets, creates a throwaway SQLite file and storage folder in the OS temp dir, migrates, runs academic:sync, starts
// local stubs for every external provider (Paystack, InfoDot, the Anthropic API, SMTP, and a proxy that refuses and
// records any attempt to reach the internet), starts the app on PHP's built-in server with several workers plus a queue
// worker, runs every spec in a real Chrome (playwright-core, channel chrome: nothing is downloaded), and tears
// everything down. No process is left behind: children run in their own process group, killed on every exit.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, readdirSync, existsSync, writeFileSync, readFileSync, createWriteStream } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';
import net from 'node:net';
import { createState, startEdgeStub, startPaystackStub, startSmtpStub, startAiStub, startInfoDotStub } from './lib/stubs.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const headed = args.includes('--headed');
const skipBuild = args.includes('--skip-build') || process.env.E2E_SKIP_BUILD === '1';
const keep = args.includes('--keep');
const filter = args.find((a) => !a.startsWith('--')) || '';

const children = [];
const closers = [];
let work = null;
let cleaned = false;

function cleanup() {
    if (cleaned) return;
    cleaned = true;
    for (const c of children) {
        try { process.kill(-c.pid, 'SIGTERM'); } catch { /* already gone */ }
    }
    for (const c of closers) { try { c(); } catch { /* ignore */ } }
    if (work && !keep) { try { rmSync(work, { recursive: true, force: true }); } catch { /* ignore */ } }
}
process.on('exit', cleanup);
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.on(sig, () => { cleanup(); process.exit(130); });
process.on('uncaughtException', (e) => { console.error(e); cleanup(); process.exit(1); });

function freePort(preferred) {
    const any = () => new Promise((res) => { const t = net.createServer(); t.listen(0, '127.0.0.1', () => { const p = t.address().port; t.close(() => res(p)); }); });
    if (!preferred) return any();
    return new Promise((res) => {
        const s = net.createServer();
        s.once('error', () => any().then(res));
        s.listen(preferred, '127.0.0.1', () => s.close(() => res(preferred)));
    });
}

function run(cmd, argv, env) {
    const r = spawnSync(cmd, argv, { cwd: root, env, encoding: 'utf8' });
    if (r.status !== 0) throw new Error(`${cmd} ${argv.join(' ')} failed:\n${r.stdout}\n${r.stderr}`);
    return r.stdout;
}

async function waitFor(url, ms = 30000) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        try { const r = await fetch(url); if (r.status === 200 && !/Fatal error/.test(await r.text())) return; } catch { /* not yet */ }
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error(`Timed out waiting for ${url}`);
}

const outputs = {};
function background(argv, env, label, extraEnv = {}, cwd = root) {
    const child = spawn(argv[0], argv.slice(1), { cwd, env: { ...env, ...extraEnv }, detached: true, stdio: ['ignore', 'pipe', 'pipe'] });
    outputs[label] = [];
    const file = work ? createWriteStream(join(work, `${label}.log`)) : null;
    const keepLine = (d) => { file?.write(d); outputs[label].push(String(d)); if (outputs[label].length > 4000) outputs[label].shift(); };
    child.stdout.on('data', (d) => { keepLine(d); if (process.env.E2E_VERBOSE) process.stdout.write(`[${label}] ${d}`); });
    child.stderr.on('data', (d) => { keepLine(d); if (process.env.E2E_VERBOSE) process.stderr.write(`[${label}] ${d}`); });
    child.on('exit', (code) => { if (!cleaned) console.error(`[${label}] exited with ${code}`); });
    children.push(child);
    return child;
}

async function main() {
    if (!skipBuild) {
        console.log('Building assets ...');
        // Built as the deploy pipeline builds it: no VITE_REVERB_* (the host runs no WebSocket server, screens poll).
        const r = spawnSync('npm', ['run', 'build'], { cwd: root, encoding: 'utf8', env: { ...process.env, VITE_REVERB_APP_KEY: '', VITE_REVERB_HOST: '', VITE_REVERB_PORT: '', VITE_REVERB_SCHEME: '' } });
        if (r.status !== 0) { console.error(r.stdout, r.stderr); process.exit(1); }
    } else if (!existsSync(join(root, 'public/build/manifest.json'))) {
        console.error('public/build is missing; run without --skip-build'); process.exit(1);
    }

    work = mkdtempSync(join(process.env.E2E_TMP || tmpdir(), 'dot-tutor-e2e-'));
    const storage = join(work, 'storage');
    for (const d of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) mkdirSync(join(storage, d), { recursive: true });
    const db = join(work, 'e2e.sqlite');
    writeFileSync(db, '');
    const out = join(root, 'tests/e2e/out');
    mkdirSync(out, { recursive: true });

    // One self-signed certificate for the https stubs (Paystack's and Anthropic's base URLs are https); PHP trusts only it.
    const certFile = join(work, 'stub-cert.pem'), keyFile = join(work, 'stub-key.pem');
    run('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', keyFile, '-out', certFile, '-days', '2', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1,DNS:api.anthropic.com'], process.env);
    const ini = join(work, 'ini');
    mkdirSync(ini);
    writeFileSync(join(ini, 'e2e.ini'), [
        // EGPCS: PHP's built-in server otherwise leaves $_ENV empty, and Laravel then ignores LARAVEL_STORAGE_PATH.
        'variables_order=EGPCS',
        'upload_max_filesize=40M', 'post_max_size=45M', 'memory_limit=512M', 'max_execution_time=60',
        `curl.cainfo=${certFile}`, `openssl.cafile=${certFile}`,
    ].join('\n') + '\n');

    const tls = { key: readFileSync(keyFile), cert: readFileSync(certFile) };
    const state = createState();
    const edge = await startEdgeStub(state);
    const paystack = await startPaystackStub(state, tls);
    const ai = await startAiStub(state, tls);
    const infodot = await startInfoDotStub(state);
    const smtp = await startSmtpStub(state);
    closers.push(edge.close, paystack.close, ai.close, infodot.close, smtp.close);
    state.ai.port = ai.port;
    const port = await freePort(Number(process.env.E2E_PORT || 8723));
    const secret = 'sk_test_e2e_' + crypto.randomBytes(12).toString('hex');
    state.appUrl = `http://127.0.0.1:${port}`;
    state.secret = secret;
    state.infodot.secret = 'e2e-infodot-' + crypto.randomBytes(8).toString('hex');
    const edgeUrl = `http://127.0.0.1:${edge.port}`;
    const infodotUrl = `http://localhost:${infodot.port}`;

    const base = {
        ...process.env,
        APP_ENV: 'local', APP_DEBUG: 'false', APP_KEY: 'base64:' + crypto.randomBytes(32).toString('base64'), APP_URL: state.appUrl,
        DB_CONNECTION: 'sqlite', DB_DATABASE: db, DB_FOREIGN_KEYS: 'true', DB_BUSY_TIMEOUT: '15000', DB_JOURNAL_MODE: 'wal', DB_TRANSACTION_MODE: 'IMMEDIATE',
        SESSION_DRIVER: 'file', SESSION_LIFETIME: '120', CACHE_STORE: 'file', QUEUE_CONNECTION: 'database', LOG_CHANNEL: 'single', LOG_LEVEL: 'debug',
        LARAVEL_STORAGE_PATH: storage, FILESYSTEM_DISK: 'local', PHP_INI_SCAN_DIR: `:${ini}`,
        BROADCAST_CONNECTION: 'log', ALLOW_REGISTRATION: 'false',
        // Laravel Boost is a dev-only package (not installed on the host): its browser logger stays out of the pages.
        BOOST_ENABLED: 'false', BOOST_BROWSER_LOGS_WATCHER: 'false',
        MAIL_MAILER: 'smtp', MAIL_SCHEME: 'smtp', MAIL_HOST: '127.0.0.1', MAIL_PORT: String(smtp.port), MAIL_USERNAME: '', MAIL_PASSWORD: '', MAIL_ENCRYPTION: '',
        MAIL_FROM_ADDRESS: 'no-reply@tutor.example.test', MAIL_FROM_NAME: 'Dot.Tutor',
        PAYSTACK_SECRET_KEY: secret, PAYSTACK_PUBLIC_KEY: 'pk_test_e2e', PAYSTACK_BASE_URL: `https://127.0.0.1:${paystack.port}`, PAYSTACK_ALLOW_LIVE: 'false',
        // A test tutor earns a few hundred rand: the payout minimum is lowered so the batch pays it.
        MONEY_PAYOUT_MINIMUM_MINOR: '1000',
        // The Anthropic API is the local stub (tunnelled by the proxy); the key is a test value the stub accepts.
        AI_ENABLED: 'true', ANTHROPIC_API_KEY: 'sk-ant-e2e-' + crypto.randomBytes(8).toString('hex'), AI_PER_MINUTE: '60',
        INFODOT_REDEEM_URL: `${infodotUrl}/api/ecosystem/redeem`, INFODOT_SERVICE_SECRET: state.infodot.secret, INFODOT_PLATFORM_KEY: 'tutor',
        // No STUN server on the internet: two browsers on one machine connect over host candidates.
        CLASSROOM_STUN_URLS: 'none',
        // Anything that tries the internet goes to the edge stub, which records the host and refuses (asserted empty).
        HTTP_PROXY: edgeUrl, HTTPS_PROXY: edgeUrl, http_proxy: edgeUrl, https_proxy: edgeUrl, NO_PROXY: '127.0.0.1,localhost', no_proxy: '127.0.0.1,localhost',
    };
    console.log('Migrating and installing reference data ...');
    run('php', ['artisan', 'migrate', '--force', '--no-interaction'], base);
    run('php', ['artisan', 'academic:sync', '--no-interaction'], base);

    // As `artisan serve` does it (the router script reads the public folder from the working directory), with workers.
    background(['php', '-S', `127.0.0.1:${port}`, join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], base, 'app', { PHP_CLI_SERVER_WORKERS: '6' }, join(root, 'public'));
    // The host runs the queue from cron every minute; here a worker drains it continuously (same jobs, less waiting).
    background(['php', 'artisan', 'queue:work', '--sleep=0.25', '--tries=5', '--timeout=60', '--no-interaction'], base, 'queue');
    await waitFor(`${state.appUrl}/up`);
    // Guard: the web workers must write into the suite's storage (sessions here after one page load).
    await fetch(`${state.appUrl}/login`);
    if (!readdirSync(join(storage, 'framework/sessions')).some((f) => !f.startsWith('.'))) {
        throw new Error('the app server is not using the suite storage folder (LARAVEL_STORAGE_PATH not seen by the web workers)');
    }

    const env = {
        ...base, E2E_BASE_URL: state.appUrl, E2E_EDGE: edgeUrl, E2E_INFODOT: infodotUrl, E2E_DB: db, E2E_OUT: out, E2E_ROOT: root,
        E2E_STORAGE: storage,
        // Chrome never reaches the internet: checkout.paystack.com resolves to the local checkout stub, every other
        // name except the app's and InfoDot's (localhost) fails to resolve, and the page's console shows it.
        E2E_CHROME_HOSTS: `MAP checkout.paystack.com:443 127.0.0.1:${paystack.port}, MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost`, E2E_HEADED: headed ? '1' : '', E2E_LOG: join(storage, 'logs/laravel.log'),
    };
    if (args.includes('--serve')) {
        console.log(`SERVING app=${state.appUrl} edge=${edgeUrl} infodot=${infodotUrl} db=${db} storage=${storage}`);
        writeFileSync(join(out, 'serve-env.json'), JSON.stringify(env, null, 1));
        await new Promise(() => {});
    }
    const specDir = join(root, 'tests/e2e/specs');
    const specs = readdirSync(specDir).filter((f) => f.endsWith('.spec.mjs') && f.includes(filter)).sort();
    if (!specs.length) { console.error('No specs match'); process.exit(1); }

    let failed = 0;
    const summary = [];
    for (const spec of specs) {
        console.log(`\n=== ${spec}`);
        const started = Date.now();
        // Asynchronous on purpose: the stubs live in THIS process and must keep answering while a spec runs.
        const code = await new Promise((res) => {
            const grep = process.env.E2E_GREP ? [`--test-name-pattern=${process.env.E2E_GREP}`] : [];
            const child = spawn(process.execPath, ['--test', '--test-force-exit', '--test-concurrency=1', '--test-timeout=900000', ...grep, join(specDir, spec)], { cwd: root, env, stdio: 'inherit' });
            child.on('exit', (c) => res(c));
            child.on('error', () => res(1));
        });
        summary.push(`${code === 0 ? 'PASS' : 'FAIL'} ${spec} (${Math.round((Date.now() - started) / 1000)} s)`);
        if (code !== 0) failed++;
    }
    if (state.outbound?.length) { console.error('Outbound internet attempts:', JSON.stringify(state.outbound)); failed++; summary.push(`FAIL outbound internet attempts: ${JSON.stringify(state.outbound)}`); }
    const log = join(storage, 'logs/laravel.log');
    if (existsSync(log)) writeFileSync(join(out, 'laravel.log'), readFileSync(log));
    for (const [label, lines] of Object.entries(outputs)) writeFileSync(join(out, `${label}.log`), lines.join(''));
    writeFileSync(join(out, 'summary.txt'), summary.join('\n') + '\n');
    console.log('\n' + summary.join('\n'));
    console.log(failed ? `\n${failed} spec file(s) failed` : '\nAll specs passed');
    cleanup();
    process.exit(failed ? 1 : 0);
}

main().catch((e) => { console.error(e); cleanup(); process.exit(1); });
