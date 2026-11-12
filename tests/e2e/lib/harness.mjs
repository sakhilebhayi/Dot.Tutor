// Shared helpers for the specs: environment, direct database reads, fixtures (support/world.php), artisan (the host's
// cron), Chrome contexts for each person, request/console watching, stub control, InfoDot sign-in and the Paystack
// checkout routed inside the browser. Adapted from Dot.Ehail's R-46 harness.
import { execFileSync } from 'node:child_process';
import { mkdirSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DatabaseSync } from 'node:sqlite';
import { chromium } from 'playwright-core';

export const env = {
    base: process.env.E2E_BASE_URL, edge: process.env.E2E_EDGE, infodot: process.env.E2E_INFODOT, db: process.env.E2E_DB,
    out: process.env.E2E_OUT || resolve(dirname(fileURLToPath(import.meta.url)), '../out'),
    root: resolve(dirname(fileURLToPath(import.meta.url)), '../../..'), headed: !!process.env.E2E_HEADED, log: process.env.E2E_LOG,
    storage: process.env.E2E_STORAGE,
};
export const PASSWORD = 'e2e-pass-12345';
mkdirSync(env.out, { recursive: true });
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let counter = 0;
export const uniq = (p = 'u') => `${p}${Date.now().toString(36)}${(counter++).toString(36)}${Math.random().toString(36).slice(2, 5)}`;

/** Fixture setup against the throwaway database (support/world.php). */
export function world(cmd, data = {}) {
    let out;
    try { out = execFileSync('php', [join(env.root, 'tests/e2e/support/world.php'), cmd, JSON.stringify(data)], { env: process.env, cwd: env.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) {
        throw new Error(`world ${cmd} failed: ${String(e.stdout || '').slice(0, 900)} ${String(e.stderr || '').slice(0, 1500)}`);
    }
    return JSON.parse(out.trim().split('\n').pop());
}

/** One run of a scheduled command, as the host's cron does it. */
export function artisan(...argv) {
    try { return execFileSync('php', ['artisan', ...argv, '--no-interaction'], { env: process.env, cwd: env.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) {
        throw new Error(`artisan ${argv.join(' ')} failed: ${String(e.stdout || '').slice(0, 600)} ${String(e.stderr || '').slice(0, 600)}`);
    }
}

/** Read-only SQL against the app's database (WAL: readers never block the app). */
export function sql(query, params = []) {
    let last;
    for (let attempt = 0; attempt < 8; attempt++) {
        const db = new DatabaseSync(env.db, { readOnly: true });
        try { db.exec('PRAGMA busy_timeout = 5000'); return db.prepare(query).all(...params); } catch (e) {
            last = e;
            if (!/locked|busy/i.test(String(e.message))) throw e;
        } finally { db.close(); }
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250 * (attempt + 1));
    }
    throw last;
}
export const one = (q, p) => sql(q, p)[0] ?? null;

/** Waits until fn() returns something truthy (polling the database or the stubs). */
export async function until(fn, { timeout = 20000, every = 250, what = 'condition' } = {}) {
    const end = Date.now() + timeout;
    let last;
    while (Date.now() < end) {
        last = await fn();
        if (last) return last;
        await sleep(every);
    }
    throw new Error(`timed out waiting for ${what} (last: ${JSON.stringify(last)?.slice(0, 200)})`);
}

// ------------------------------------------------------------------ stubs

export async function stub(body) {
    if (body === undefined) return (await fetch(`${env.edge}/__control`)).json();
    return (await fetch(`${env.edge}/__control`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
}
export async function stubPost(path, body) {
    const r = await fetch(`${env.edge}/__control/${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) });
    return r.json();
}
export async function mailsTo(address) { return (await stub()).mails.filter((m) => m.to.includes(address)); }

// Hosts the browser asks for that Playwright answers from the stubs (never contacted), and the InfoDot stub.
const routedHosts = new Set();

/**
 * Paystack's hosted checkout: Chrome resolves checkout.paystack.com to the local Paystack stub (host-resolver rules
 * in browser()), so the app's redirect after "Pay" is answered locally and never reaches the internet. A Playwright
 * route cannot do this: routes do not see the redirected request (a first draft of this harness reached the real
 * checkout.paystack.com that way). This only marks the host as expected for the hygiene check.
 */
export async function interceptCheckout() {
    routedHosts.add('checkout.paystack.com');
}

// ------------------------------------------------------------------ people and browsers

let browserPromise = null;
export function browser() {
    browserPromise ??= chromium.launch({
        channel: 'chrome', headless: !env.headed,
        args: [...(process.env.E2E_CHROME_HOSTS ? [`--host-resolver-rules=${process.env.E2E_CHROME_HOSTS}`] : []), '--disable-features=Translate,WebRtcHideLocalIpsWithMdns', '--force-prefers-reduced-motion', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
    });
    return browserPromise;
}
export async function closeBrowser() { if (browserPromise) { const b = await browserPromise; await b.close(); browserPromise = null; } }

/** A person made directly in the database (support/world.php) with a password for the local login form. */
export function makePerson(cmd = 'user', label = 'Person', extra = {}) {
    const email = `${uniq(label.toLowerCase().replace(/\W/g, ''))}@example.test`;
    return { email, password: PASSWORD, name: extra.name || `${label} ${uniq('').slice(0, 4)}`, ...world(cmd, { name: extra.name || label, email, password: PASSWORD, ...extra }) };
}

const openContexts = new Set();

/** After a test: screenshots every page still open (out/left-<label>-<n>.png), then closes the contexts. */
export async function closeLeftovers(label) {
    let n = 0;
    for (const ctx of [...openContexts]) {
        for (const page of ctx.pages()) await shot(page, `left-${label.replace(/\W+/g, '-').slice(0, 50)}-${n++}`);
        await ctx.close().catch(() => {});
        openContexts.delete(ctx);
    }
    return n;
}

/** A new context (390 phone by default); optionally signed in through the real login form. */
export async function newContext({ user = null, width = 390, height = 844, dark = false, media = false } = {}) {
    const b = await browser();
    const ctx = await b.newContext({
        baseURL: env.base, ignoreHTTPSErrors: true, viewport: { width, height }, colorScheme: dark ? 'dark' : 'light', timezoneId: 'Africa/Johannesburg', locale: 'en-ZA',
        acceptDownloads: true, permissions: media ? ['camera', 'microphone'] : [],
    });
    await ctx.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`)); });
    openContexts.add(ctx);
    ctx.on('close', () => openContexts.delete(ctx));
    if (user) await signIn(ctx, user);
    return ctx;
}

/** The local password form (people made by world.php). Returns where the app landed after sign-in. */
export async function signIn(ctx, user) {
    const page = await ctx.newPage();
    await page.goto('/login');
    await page.fill('input[name=email]', user.email);
    await page.fill('input[name=password]', user.password || PASSWORD);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type=submit]')]);
    const landed = new URL(page.url()).pathname;
    await page.close();
    return landed;
}

/** Sign-in as production does it: InfoDot's launch page posts a one-time token; the app redeems it with InfoDot. */
export async function infoDotSignIn(page, { email, name, redirect = '' }) {
    routedHosts.add(new URL(env.infodot).host);
    await page.goto(`${env.infodot}/launch?email=${encodeURIComponent(email)}&name=${encodeURIComponent(name)}${redirect ? `&redirect=${encodeURIComponent(redirect)}` : ''}`);
    await Promise.all([page.waitForURL((u) => u.origin === new URL(env.base).origin, { timeout: 15000 }), page.click('#open')]);
    return new URL(page.url()).pathname;
}

/** Watches a page: console errors, page errors, every request URL, server errors. */
export function watch(page, store = {}) {
    store.errors ??= []; store.requests ??= []; store.bad ??= [];
    page.on('console', (m) => { if (m.type() === 'error') store.errors.push(`${m.text()} @ ${m.location().url}`); });
    page.on('pageerror', (e) => store.errors.push(`pageerror: ${e.message}`));
    page.on('request', (r) => store.requests.push(r.url()));
    page.on('response', (r) => { if (r.status() >= 500) store.bad.push(`${r.status()} ${r.url()}`); });
    return store;
}

/** The hygiene every journey asserts at the end: no 5xx, no console errors (expected 4xx refusals aside), no third-party host. */
export function hygiene(store, { allowStatuses = [401, 403, 404, 409, 419, 422, 429] } = {}) {
    const re = new RegExp(`status of (${allowStatuses.join('|')})`);
    return {
        bad: store.bad,
        errors: store.errors.filter((e) => !re.test(e)),
        external: externalHosts(store.requests),
    };
}

/** Hosts a page contacted other than the app itself and the stubs Playwright answers. */
export function externalHosts(urls) {
    const allowed = new Set([new URL(env.base).host, ...routedHosts]);
    return [...new Set(urls.filter((u) => /^https?:/.test(u)).map((u) => new URL(u).host).filter((h) => !allowed.has(h)))];
}

export async function cspViolations(page) {
    try { return await page.evaluate(() => window.__csp || []); } catch { return []; }
}

export async function shot(page, name) {
    try { await page.screenshot({ path: join(env.out, `${name}.png`), fullPage: false }); } catch { /* page gone */ }
}

/** Runs a named step; on failure takes a screenshot (out/fail-<name>.png) and rethrows with the step name. */
export async function step(page, name, fn) {
    try { return await fn(); } catch (e) {
        await shot(page, `fail-${name.replace(/\W+/g, '-').slice(0, 60)}`);
        e.message = `[step: ${name}] ${e.message}`; throw e;
    }
}

/** JSON call from inside a signed-in page (same session, CSRF token from the page). */
export async function api(page, method, url, body, headers = {}) {
    return page.evaluate(async ([m, u, b, h]) => {
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const r = await fetch(u, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token || '', 'X-Requested-With': 'XMLHttpRequest', ...h }, body: b ? JSON.stringify(b) : undefined, credentials: 'same-origin' });
        let data = null; try { data = await r.json(); } catch { /* not json */ }
        return { status: r.status, data };
    }, [method, url, body ?? null, headers]);
}

/** A form POST (urlencoded, as a browser form submits) from inside a signed-in page; returns status and final URL. */
export async function formPost(page, url, fields) {
    return page.evaluate(async ([u, f]) => {
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const body = new URLSearchParams();
        body.append('_token', token || '');
        for (const [k, v] of Object.entries(f)) { if (Array.isArray(v)) v.forEach((x) => body.append(`${k}[]`, x)); else body.append(k, v); }
        const r = await fetch(u, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'text/html' } });
        return { status: r.status, url: r.url, redirected: r.redirected };
    }, [url, fields]);
}

export function laravelLogTail(n = 40) {
    if (!env.log || !existsSync(env.log)) return '';
    return readFileSync(env.log, 'utf8').split('\n').slice(-n).join('\n');
}

/** Small real files for uploads: a PNG made by PHP's GD, a one-page PDF, a text file. */
export function fixtureFile(kind) {
    const file = join(env.out, `fixture.${kind}`);
    if (existsSync(file)) return file;
    if (kind === 'png') execFileSync('php', ['-r', '$i = imagecreatetruecolor(640, 420); imagefill($i, 0, 0, imagecolorallocate($i, 230, 226, 214)); imagestring($i, 5, 40, 40, "E2E STUDY NOTES", imagecolorallocate($i, 20, 20, 20)); imagepng($i, $argv[1]);', file]);
    if (kind === 'jpg') execFileSync('php', ['-r', '$i = imagecreatetruecolor(640, 420); imagefill($i, 0, 0, imagecolorallocate($i, 210, 220, 230)); imagestring($i, 5, 40, 40, "E2E EVIDENCE", imagecolorallocate($i, 20, 20, 20)); imagejpeg($i, $argv[1], 85);', file]);
    if (kind === 'pdf') {
        const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>', null, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        const stream = 'BT /F1 18 Tf 72 760 Td (E2E study notes: functions and graphs) Tj ET';
        objs[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
        let pdf = '%PDF-1.4\n'; const offs = [];
        objs.forEach((o, i) => { offs.push(pdf.length); pdf += `${i + 1} 0 obj\n${o}\nendobj\n`; });
        const xref = pdf.length;
        pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
        writeFileSync(file, pdf);
    }
    if (kind === 'txt') writeFileSync(file, 'E2E plain notes\n');
    return file;
}

/** A spec's evidence log: every journey step with its result, written to out/evidence-<spec>.json for docs/R47_QA.md. */
export function evidenceLog(name) {
    const rows = [];
    const file = join(env.out, `evidence-${name}.json`);
    return {
        add(stepNo, title, evidence) { rows.push({ step: stepNo, title, result: 'Pass', evidence }); writeFileSync(file, JSON.stringify(rows, null, 1)); },
        rows,
    };
}
