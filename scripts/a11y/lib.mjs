// R-47 a11y (DEVELOPMENT ONLY; docs/R47_A11Y.md): shared helpers. Real Chrome through playwright-core and axe-core,
// both pinned devDependencies (no CDN), against the dev server on the demo database (scripts/a11y/serve.sh).
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
export const OUT = join(ROOT, 'storage/a11y');
mkdirSync(OUT, { recursive: true });
const AXE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

export const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
export const has = (n) => process.argv.includes(`--${n}`);
export const BASE = arg('base', process.env.BASE || 'http://127.0.0.1:8721');
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
export const HEIGHT = { 320: 640, 390: 844, 768: 1024, 1440: 900 };
export const PASSWORD = process.env.DEMO_PASSWORD || 'password';

export async function browser() {
    return chromium.launch({ channel: 'chrome', headless: !has('headed'), args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
}

/** A signed-in person (through the real sign-in form) in their own context. */
export async function person(b, email, { width = 390, theme = 'light', reducedMotion = 'no-preference' } = {}) {
    const ctx = await b.newContext({
        viewport: { width, height: HEIGHT[width] || 900 }, deviceScaleFactor: 1, colorScheme: theme === 'dark' ? 'dark' : 'light', reducedMotion,
        isMobile: false, hasTouch: width < 768, permissions: ['camera', 'microphone'],
    });
    await ctx.addInitScript((t) => { try { localStorage.setItem('td-theme', t); } catch (e) { /* storage blocked */ } }, theme);
    const page = await ctx.newPage();
    if (email) {
        await page.goto(`${BASE}/login`);
        await page.fill('input[name=email]', email);
        await page.fill('input[name=password]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.press('input[name=password]', 'Enter')]);
        if (/\/login/.test(page.url())) throw new Error(`sign-in failed for ${email}`);
    }
    const errors = [];
    page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
    page.on('console', (m) => m.type() === 'error' && !/favicon|net::ERR/.test(m.text()) && errors.push('console ' + m.text().slice(0, 160)));
    return { ctx, page, errors };
}

/** The product's theme on the open page without a reload: storage, attribute and media agree. */
export async function setTheme(page, theme) {
    await page.emulateMedia({ colorScheme: theme === 'dark' ? 'dark' : 'light' });
    await page.evaluate((t) => {
        try { localStorage.setItem('td-theme', t); } catch (e) { /* storage blocked */ }
        document.documentElement.setAttribute('data-theme', t);
    }, theme);
}

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
export const IMPACT = { critical: 4, serious: 3, moderate: 2, minor: 1 };

/** axe-core on the settled page (animations finished or 2.5 s). `enhanced` adds AAA contrast (young learners). */
export async function axe(page, { enhanced = false } = {}) {
    await page.evaluate(() => Promise.race([Promise.all(document.getAnimations().map((a) => a.finished.catch(() => {}))), new Promise((r) => setTimeout(r, 2500))]));
    if (!(await page.evaluate(() => typeof window.axe === 'object'))) await page.evaluate(AXE);
    const tags = enhanced ? [...TAGS, 'wcag2aaa'] : TAGS;
    return page.evaluate(async ({ tags, enhanced }) => {
        const r = await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'], rules: enhanced ? {} : { 'color-contrast-enhanced': { enabled: false } } });
        return r.violations
            .filter((v) => enhanced || !v.tags.includes('wcag2aaa'))
            .map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes.slice(0, 4).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 180), why: (n.any.concat(n.all, n.none)[0] || {}).message })) }));
    }, { tags, enhanced });
}

/** Facts axe does not report: horizontal overflow, h1 count, duplicate ids, small touch targets (24 px; 44 px on the young step). */
export async function facts(page) {
    return page.evaluate(() => {
        const young = document.documentElement.dataset.tdStep === 'young';
        const min = young ? 44 : 24;
        const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && !e.closest('[hidden],[inert],[aria-hidden=true]'); };
        // WCAG 2.5.8 exceptions: inline links inside sentences, and controls whose size the browser decides.
        const inline = (e) => e.matches('a') && e.closest('p,li:not([role]),dd,td,figcaption,.td-prose,.prose') && getComputedStyle(e).display === 'inline';
        const small = [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea,summary,[role=button],[role=tab],[role=link]')]
            .filter((e) => visible(e) && !inline(e))
            .filter((e) => {
                // A small control still passes with a 24 px (44 px) circle of spacing; approximate with the hit area of its label.
                const r = (e.matches('input[type=radio],input[type=checkbox]') && e.labels?.[0] ? e.labels[0] : e).getBoundingClientRect();
                return r.height < min - 0.5 || r.width < min - 0.5;
            })
            .map((e) => `${e.tagName.toLowerCase()}${e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/)[0] : ''}[${(e.getAttribute('aria-label') || e.textContent || e.value || '').trim().replace(/\s+/g, ' ').slice(0, 24)}] ${Math.round(e.getBoundingClientRect().width)}x${Math.round(e.getBoundingClientRect().height)}`);
        const ids = new Map(); for (const e of document.querySelectorAll('[id]')) ids.set(e.id, (ids.get(e.id) || 0) + 1);
        return {
            overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            h1: document.querySelectorAll('h1').length,
            dupIds: [...ids].filter(([, n]) => n > 1).map(([id]) => id),
            small: small.slice(0, 8), smallCount: small.length, young,
            path: location.pathname, title: document.title,
        };
    });
}

export function php(script, args = []) {
    return execFileSync('php', [join(ROOT, script), ...args], { cwd: ROOT, encoding: 'utf8' });
}

export function writeJson(name, data) { const p = join(OUT, name); writeFileSync(p, JSON.stringify(data, null, 2)); return p; }
