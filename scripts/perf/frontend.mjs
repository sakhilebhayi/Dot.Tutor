// R-47 front end on a throttled mid-range Android profile (DEVELOPMENT ONLY; docs/R47_PERF.md section 4).
//
//   node scripts/perf/frontend.mjs --base http://127.0.0.1:8731 --cookies storage/perf/cookies.json [--net fast3g|slow3g|4g] [--only regex]
//
// Real Chrome through playwright-core (pinned devDependency), 390 x 844 touch phone, CPU throttled 4x and the
// DevTools network profile (Fast 3G: 562.5 ms RTT, 1.47 Mbps down, 675 kbps up; Slow 3G: 2 s RTT, 400 kbps down).
// For each key page, a cold load in a fresh context: FCP, LCP, DOMContentLoaded, load, total blocking time, bytes on
// the wire by type and the largest files. The built-in server does not compress, so the bytes are uncompressed:
// pessimistic against a host that gzips CSS and JS (Vite's gzip sizes are reported beside them in the doc).
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright-core';

const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8731');
const cookies = JSON.parse(readFileSync(arg('cookies', 'storage/perf/cookies.json'), 'utf8'));
const pages = JSON.parse(readFileSync(arg('pages', 'storage/perf/frontend-pages.json'), 'utf8'));
const only = arg('only') ? new RegExp(arg('only')) : null;
const NETS = { fast3g: { latency: 562.5, down: 1474.56, up: 675 }, slow3g: { latency: 2000, down: 400, up: 400 }, '4g': { latency: 170, down: 9000, up: 9000 } };
const net = NETS[arg('net', 'fast3g')];
const host = new URL(BASE).hostname;

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
for (const p of pages) {
    if (only && !only.test(p.name)) continue;
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
        userAgent: 'Mozilla/5.0 (Linux; Android 13; SM-A346E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36' });
    if (p.as) {
        const [name, ...rest] = cookies[p.as].split('=');
        await context.addCookies([{ name, value: rest.join('='), domain: host, path: '/', httpOnly: true, sameSite: 'Lax' }]);
    }
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: net.latency, downloadThroughput: (net.down * 1024) / 8, uploadThroughput: (net.up * 1024) / 8 });
    const files = new Map();
    cdp.on('Network.requestWillBeSent', (e) => files.set(e.requestId, { url: e.request.url, type: e.type }));
    cdp.on('Network.loadingFinished', (e) => { const f = files.get(e.requestId); if (f) f.bytes = e.encodedDataLength; });
    await page.addInitScript(() => {
        window.__perf = { lcp: 0, longTasks: [] };
        new PerformanceObserver((l) => l.getEntries().forEach((e) => (window.__perf.lcp = e.startTime))).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__perf.longTasks.push([e.startTime, e.duration]))).observe({ type: 'longtask', buffered: true });
    });
    let status = 0;
    try {
        const res = await page.goto(BASE + p.path, { waitUntil: 'load', timeout: 120000 });
        status = res.status();
        await page.waitForTimeout(2500);
    } catch (e) { status = 'ERR ' + e.message.split('\n')[0]; }
    const m = await page.evaluate(() => {
        const nav = performance.getEntriesByType('navigation')[0];
        const fcp = performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? null;
        const tbt = window.__perf.longTasks.reduce((a, [, d]) => a + Math.max(0, d - 50), 0);
        const imgs = [...document.images].map((i) => ({ src: i.currentSrc || i.src, w: i.naturalWidth, shown: Math.round(i.getBoundingClientRect().width), lazy: i.loading === 'lazy', inView: i.getBoundingClientRect().top < innerHeight }));
        return { fcp: Math.round(fcp), lcp: Math.round(window.__perf.lcp), dcl: Math.round(nav.domContentLoadedEventEnd), load: Math.round(nav.loadEventEnd), tbt: Math.round(tbt), imgs, title: document.title };
    }).catch(() => ({}));
    const done = [...files.values()].filter((f) => f.bytes !== undefined);
    const byType = {};
    for (const f of done) { const k = f.type || 'Other'; byType[k] = byType[k] || { n: 0, kb: 0 }; byType[k].n++; byType[k].kb += f.bytes / 1024; }
    Object.values(byType).forEach((v) => (v.kb = Math.round(v.kb * 10) / 10));
    const total = Math.round(done.reduce((a, f) => a + f.bytes, 0) / 102.4) / 10;
    const largest = done.sort((a, b) => b.bytes - a.bytes).slice(0, 4).map((f) => `${new URL(f.url).pathname.split('/').pop()} ${Math.round(f.bytes / 102.4) / 10}KB`);
    const row = { name: p.name, path: p.path, status, ...m, total_kb: total, by_type: byType, largest };
    results.push(row);
    console.log(`${String(status).padEnd(4)} ${p.name.padEnd(30)} FCP ${String(m.fcp).padStart(5)} LCP ${String(m.lcp).padStart(5)} DCL ${String(m.dcl).padStart(5)} load ${String(m.load).padStart(5)} TBT ${String(m.tbt).padStart(4)}  ${String(total).padStart(6)} KB  ${Object.entries(byType).map(([k, v]) => `${k} ${v.n}/${v.kb}`).join(' ')}  | ${largest.join(', ')}${(m.imgs || []).filter((i) => !i.lazy && !i.inView).length ? '  [eager offscreen imgs]' : ''}`);
    await context.close();
}
await browser.close();
if (arg('out')) writeFileSync(arg('out'), JSON.stringify(results, null, 2));
