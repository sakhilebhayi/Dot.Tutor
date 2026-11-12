// R-47 load run (DEVELOPMENT ONLY; docs/R47_PERF.md section 3). Live classrooms polling as the browser does (every
// 2 s calm, the real request body and headers), a few room tabs polling every 30 s with ETags, and a booking storm:
// every booker asks for one of a few open slots at the same second, a fifth of them double-tapping with one
// Idempotency-Key from two "devices". Prints per-endpoint counts, rates and latency percentiles.
//
//   node scripts/perf/load.mjs --base http://127.0.0.1:8731 --plan storage/perf/load.json --seconds 60
import { readFileSync } from 'node:fs';

const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8731');
const plan = JSON.parse(readFileSync(arg('plan', 'storage/perf/load.json'), 'utf8'));
const SECONDS = Number(arg('seconds', 60));
const POLL_MS = Number(arg('poll-ms', 2000));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const stats = {};

async function call(name, who, method, path, body, extra = {}) {
    const started = performance.now();
    let status = 0; let bytes = 0; let json = null; let headers = null;
    try {
        const res = await fetch(BASE + path, {
            method,
            headers: { Cookie: who.cookie, 'X-CSRF-TOKEN': who.csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? { 'Content-Type': 'application/json' } : {}), ...extra },
            body: body ? JSON.stringify(body) : undefined,
        });
        status = res.status; headers = res.headers;
        const text = await res.text(); bytes = text.length;
        try { json = JSON.parse(text); } catch { /* 304 or html */ }
    } catch (e) { status = 'ERR ' + (e.cause?.code || e.message); }
    const ms = performance.now() - started;
    const s = (stats[name] ??= { n: 0, ms: [], codes: {}, bytes: 0, hdr: 0, setCookie: 0 });
    s.n++; s.ms.push(ms); s.codes[status] = (s.codes[status] || 0) + 1; s.bytes += bytes;
    if (headers) { let h = 17; headers.forEach((v, k) => { h += k.length + v.length + 4; }); for (const c of headers.getSetCookie?.() || []) h += c.length + 14; s.hdr += h; }
    if (headers?.get('set-cookie')) s.setCookie++;
    return { status, json, headers };
}

const pct = (arr, p) => { if (!arr.length) return 0; const a = [...arr].sort((x, y) => x - y); return a[Math.min(a.length - 1, Math.floor((p / 100) * a.length))]; };
const end = Date.now() + SECONDS * 1000;

async function participant(lesson, who, client) {
    const h = { 'X-Client-Id': client };
    const joined = await call('classroom join', who, 'POST', `/lessons/${lesson}/classroom/api/join`, { client_id: client }, h);
    if (joined.status !== 200) return;
    let since = 0, chatSince = 0, boardSince = 0, filesSince = -1, notesVersion = 0;
    await sleep(Math.random() * POLL_MS);
    while (Date.now() < end) {
        const r = await call('classroom poll', who, 'POST', `/lessons/${lesson}/classroom/api/poll`,
            { since, chat_since: chatSince, board_since: boardSince, notes_version: notesVersion, files_since: filesSince, mic: true, cam: true, sharing: false }, h);
        if (r.json) { // as room.js reads the snapshot
            since = Math.max(since, r.json.last_id | 0);
            for (const m of r.json.chat || []) chatSince = Math.max(chatSince, m.id);
            if (r.json.board?.length) boardSince = r.json.board[r.json.board.length - 1].id;
            if (r.json.notes) notesVersion = r.json.notes.version;
            if (r.json.files) filesSince = r.json.files_latest | 0;
        }
        await sleep(POLL_MS);
    }
}

async function roomTab(who) {
    let etag = null;
    await sleep(Math.random() * 30000);
    while (Date.now() < end) {
        const r = await call('room activity', who, 'GET', `/rooms/${plan.room}/activity`, null, etag ? { 'If-None-Match': etag } : {});
        etag = r.headers?.get('etag') || etag;
        await sleep(30000);
    }
}

async function storm() {
    await sleep(10000);
    const results = await Promise.all(plan.bookers.map((who, i) => {
        const t = plan.targets[i % plan.targets.length];
        const body = { subject_id: t.subject, starts_at: t.starts_at };
        const key = { 'Idempotency-Key': `storm-${who.user}-${i}` };
        const one = call('booking request', who, 'POST', `/api/tutor/offerings/${t.offering}/bookings`, body, key);
        return i % 5 === 0 ? Promise.all([one, call('booking request', who, 'POST', `/api/tutor/offerings/${t.offering}/bookings`, body, key)]) : one;
    }));
    return results;
}

const t0 = Date.now();
const tasks = [];
for (const c of plan.classrooms) {
    tasks.push(participant(c.lesson, c.tutor, `t-${c.lesson}`), participant(c.lesson, c.learner, `l-${c.lesson}`));
}
for (const who of plan.bookers.slice(0, 20)) tasks.push(roomTab(who));
tasks.push(storm());
await Promise.all(tasks);
const elapsed = (Date.now() - t0) / 1000;

console.log(`${plan.classrooms.length} classrooms (${plan.classrooms.length * 2} people polling every ${POLL_MS / 1000} s), ${plan.bookers.length} bookers on ${plan.targets.length} slots, ${elapsed.toFixed(0)} s`);
let total = 0;
for (const [name, s] of Object.entries(stats)) {
    total += s.n;
    console.log(`${name.padEnd(18)} n=${String(s.n).padStart(6)}  ${(s.n / elapsed).toFixed(1).padStart(6)}/s  p50 ${pct(s.ms, 50).toFixed(0).padStart(5)} ms  p95 ${pct(s.ms, 95).toFixed(0).padStart(5)} ms  p99 ${pct(s.ms, 99).toFixed(0).padStart(5)} ms  body ${(s.bytes / Math.max(1, s.n)).toFixed(0)} B + headers ${(s.hdr / Math.max(1, s.n)).toFixed(0)} B  set-cookie ${s.setCookie}  ${JSON.stringify(s.codes)}`);
}
console.log(`total ${total} requests, ${(total / elapsed).toFixed(1)}/s`);
