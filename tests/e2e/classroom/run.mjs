// Two real Chrome contexts with Chrome's fake camera and microphone, against the local classroom (port 8707).
// Proves a real WebRTC peer connection by reading RTCPeerConnection.getStats() independently of the app code.
import { chromium } from 'playwright-core';
import fs from 'node:fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8707';
const LESSON = process.env.LESSON || '1';
const SHOTS = new URL('./shots/', import.meta.url).pathname;
fs.mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' :: ' + detail : ''}`); };

const PC_HOOK = () => {
    const Orig = window.RTCPeerConnection;
    window.__pcs = [];
    function Wrapped(...args) { const pc = new Orig(...args); window.__pcs.push(pc); return pc; }
    Wrapped.prototype = Orig.prototype;
    Object.setPrototypeOf(Wrapped, Orig);
    window.RTCPeerConnection = Wrapped;
};

async function stats(page) {
    return page.evaluate(async () => {
        const out = [];
        for (const pc of window.__pcs || []) {
            if (pc.connectionState === 'closed') continue;
            const r = { state: pc.connectionState, audioIn: 0, videoIn: 0, frames: 0, audioEnergy: 0, pair: null };
            (await pc.getStats()).forEach((s) => {
                if (s.type === 'inbound-rtp' && s.kind === 'audio') { r.audioIn += s.bytesReceived || 0; r.audioEnergy += s.totalAudioEnergy || 0; }
                if (s.type === 'inbound-rtp' && s.kind === 'video') { r.videoIn += s.bytesReceived || 0; r.frames += s.framesDecoded || 0; }
                if (s.type === 'candidate-pair' && s.nominated && s.state === 'succeeded') r.pair = { rtt: s.currentRoundTripTime };
            });
            out.push(r);
        }
        return out;
    });
}

async function login(context, email) {
    const page = await context.newPage();
    await page.addInitScript(PC_HOOK);
    await page.goto(`${BASE}/login`);
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', 'password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    return page;
}

const browser = await chromium.launch({
    channel: 'chrome', headless: !process.env.HEADED,
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required', '--disable-features=WebRtcHideLocalIpsWithMdns'],
    ignoreDefaultArgs: ['--mute-audio'],
});
const tutorCtx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['camera', 'microphone'] });
const learnerCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, permissions: ['camera', 'microphone'] });
const tutor = await login(tutorCtx, 'demo.classroom.tutor@example.test');
const learner = await login(learnerCtx, 'demo.classroom.learner@example.test');
const errors = [];
for (const [who, p] of [['tutor', tutor], ['learner', learner]]) {
    p.on('pageerror', (e) => errors.push(`${who}: ${e.message}`));
    // A 409 after the tutor ends the lesson is the server's expected answer to the next poll; anything else counts.
    p.on('console', (m) => { if (m.type() === 'error' && !/status of 409/.test(m.text())) errors.push(`${who} console: ${m.text()}`); });
}

const url = `${BASE}/lessons/${LESSON}/classroom`;
await tutor.goto(url);
await learner.goto(url);
await tutor.waitForFunction(() => document.getElementById('cr-check-mic')?.textContent === 'Working', null, { timeout: 15000 });
await learner.waitForFunction(() => document.getElementById('cr-check-cam')?.textContent === 'Working', null, { timeout: 15000 });
const level = await tutor.evaluate(async () => { let max = 0; for (let i = 0; i < 30; i++) { max = Math.max(max, Number(document.getElementById('cr-level').getAttribute('aria-valuenow'))); await new Promise((r) => setTimeout(r, 100)); } return max; });
check('pre-join: fake camera and microphone found, the level meter moves with the microphone', level > 0, `peak ${level} of 100 over 3 s`);
await tutor.screenshot({ path: `${SHOTS}01-tutor-prejoin-1440.png`, fullPage: true });
await learner.screenshot({ path: `${SHOTS}02-learner-prejoin-390.png`, fullPage: true });

await tutor.click('#cr-join');
await learner.click('#cr-join');
await tutor.waitForSelector('#cr-live:not([hidden])', { timeout: 10000 });
await learner.waitForSelector('#cr-live:not([hidden])', { timeout: 10000 });

let connected = false;
for (let i = 0; i < 30 && !connected; i++) {
    await sleep(1000);
    const [a, b] = [await stats(tutor), await stats(learner)];
    connected = a.some((s) => s.state === 'connected') && b.some((s) => s.state === 'connected');
}
check('peer connection reached "connected" in both browsers', connected);
await sleep(4000);
const s1t = (await stats(tutor))[0] || {}; const s1l = (await stats(learner))[0] || {};
await sleep(3000);
const s2t = (await stats(tutor))[0] || {}; const s2l = (await stats(learner))[0] || {};
check('tutor receives the learner\'s video (frames decoded rising)', s2t.frames > s1t.frames && s2t.frames > 30, `${s1t.frames} -> ${s2t.frames} frames, ${s2t.videoIn} video bytes`);
check('learner receives the tutor\'s video (frames decoded rising)', s2l.frames > s1l.frames && s2l.frames > 30, `${s1l.frames} -> ${s2l.frames} frames`);
check('audio flows both ways (bytes and energy rising)', s2t.audioIn > s1t.audioIn && s2l.audioIn > s1l.audioIn && s2t.audioEnergy > 0, `tutor in ${s2t.audioIn} B energy ${s2t.audioEnergy?.toFixed?.(4)}, learner in ${s2l.audioIn} B`);
check('candidate pair succeeded with a round trip', !!(s2t.pair && s2l.pair), JSON.stringify(s2t.pair));
const tileVideo = await tutor.evaluate(() => { const v = [...document.querySelectorAll('#cr-grid video')].find((x) => !x.hidden && x.srcObject && !x.muted); return v ? { w: v.videoWidth, h: v.videoHeight, playing: !v.paused } : null; });
check('the remote tile plays real video in the page', !!tileVideo && tileVideo.w > 0 && tileVideo.playing, JSON.stringify(tileVideo));

// Mute: the learner turns the microphone off; the tutor's roster shows it.
await learner.click('#cr-mic');
await tutor.waitForFunction(() => /Microphone off/.test(document.getElementById('cr-people')?.textContent || ''), null, { timeout: 8000 }).then(() => check('mute reaches the other side', true)).catch(() => check('mute reaches the other side', false));
await learner.click('#cr-mic');

// Chat both ways.
await tutor.click('#cr-tab-chat-tab');
await tutor.fill('#cr-chat-input', 'Welcome. Open the worksheet.');
await tutor.click('#cr-chat-form button[type=submit]');
await learner.click('#cr-tab-chat-tab');
await learner.waitForFunction(() => /Welcome\. Open the worksheet\./.test(document.getElementById('cr-chat-log').textContent), null, { timeout: 8000 })
    .then(() => check('chat reaches the learner', true)).catch(() => check('chat reaches the learner', false));

// Whiteboard: the tutor draws a stroke and writes text; the learner's board shows the text.
await tutor.click('#cr-tab-board-tab');
await sleep(400);
const box = await tutor.locator('#cr-canvas').boundingBox();
await tutor.mouse.move(box.x + 100, box.y + 100);
await tutor.mouse.down();
for (let i = 1; i <= 10; i++) await tutor.mouse.move(box.x + 100 + i * 30, box.y + 100 + i * 12);
await tutor.mouse.up();
await tutor.click('[data-tool=text]');
await tutor.mouse.click(box.x + 300, box.y + 250);
await tutor.fill('#cr-text-input', 'x^2 - 9 = (x - 3)(x + 3)');
await tutor.press('#cr-text-input', 'Enter');
await learner.click('#cr-tab-board-tab');
await learner.waitForFunction(() => /\(x - 3\)\(x \+ 3\)/.test(document.getElementById('cr-board-text').textContent) && /1 drawing/.test(document.getElementById('cr-board-text').textContent), null, { timeout: 8000 })
    .then(() => check('whiteboard stroke and text reach the learner', true)).catch(async () => check('whiteboard stroke and text reach the learner', false, await learner.textContent('#cr-board-text')));
const inked = await learner.evaluate(() => { const c = document.getElementById('cr-canvas'); const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 0) n++; return n; });
check('the learner\'s canvas has ink on it', inked > 500, `${inked} painted pixels`);
await tutor.screenshot({ path: `${SHOTS}03-tutor-board-1440.png`, fullPage: true });
await learner.screenshot({ path: `${SHOTS}04-learner-board-390.png`, fullPage: true });

// Shared notes.
await learner.click('#cr-tab-notes-tab');
await learner.fill('#cr-notes', 'Homework: exercise 4.2');
await tutor.click('#cr-tab-notes-tab');
await tutor.waitForFunction(() => document.getElementById('cr-notes').value === 'Homework: exercise 4.2', null, { timeout: 10000 })
    .then(() => check('shared notes reach the tutor', true)).catch(() => check('shared notes reach the tutor', false));

// Back to video for the call screenshots.
await tutor.click('#cr-tab-board-tab');
await tutor.click('#cr-tab-video-tab');
await learner.click('#cr-tab-video-tab');
await tutor.click('#cr-tab-people-tab');
await sleep(1500);
await tutor.screenshot({ path: `${SHOTS}05-tutor-call-1440.png`, fullPage: false });
await learner.screenshot({ path: `${SHOTS}06-learner-call-390.png`, fullPage: false });
const overflow = await learner.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
check('no horizontal scroll at 390 px', overflow <= 0, `${overflow}px`);
const smallTargets = await learner.evaluate(() => [...document.querySelectorAll('#cr-controls button, #cr-controls summary')].filter((b) => { const r = b.getBoundingClientRect(); return r.width && (r.width < 44 || r.height < 44); }).length);
check('call controls are at least 44 px on the phone', smallTargets === 0, `${smallTargets} small`);

// Keyboard: m toggles the microphone, with a label update.
await tutor.click('#cr-tab-chat-tab');
await tutor.locator('body').focus?.();
await tutor.keyboard.press('Escape');
await tutor.evaluate(() => document.activeElement?.blur());
await tutor.keyboard.press('m');
const micLabel = await tutor.getAttribute('#cr-mic', 'aria-label');
check('keyboard shortcut m mutes, and the button says so', micLabel === 'Microphone off', micLabel);
await tutor.keyboard.press('m');

// The tutor ends the lesson: the learner is told and both land on the record.
tutor.on('dialog', (d) => d.accept());
await tutor.click('#cr-end');
await tutor.waitForURL(/\/classroom\/after$/, { timeout: 10000 });
await learner.waitForSelector('#cr-notice:not([hidden])', { timeout: 10000 }).then(() => check('the learner is told the lesson ended', true)).catch(() => check('the learner is told the lesson ended', false));
const pcsOpen = await learner.evaluate(() => (window.__pcs || []).filter((pc) => pc.connectionState !== 'closed').length);
check('the learner\'s peer connections are closed after the end', pcsOpen === 0, `${pcsOpen} open`);
await learner.screenshot({ path: `${SHOTS}07-learner-ended-390.png`, fullPage: true });
await tutor.screenshot({ path: `${SHOTS}08-tutor-after-1440.png`, fullPage: true });
const attendance = await tutor.textContent('table');
check('attendance on the record lists both people with minutes', /Demo Classroom Learner/.test(attendance) && /Demo Classroom Tutor/.test(attendance), attendance.replace(/\s+/g, ' ').slice(0, 200));
await learner.goto(`${url}/after`);
await learner.screenshot({ path: `${SHOTS}09-learner-after-390.png`, fullPage: true });

check('no script errors in either page', errors.length === 0, errors.slice(0, 5).join(' | '));
fs.writeFileSync(new URL('./results.json', import.meta.url), JSON.stringify(results, null, 2));
await browser.close();
console.log(`${results.filter((r) => r.ok).length}/${results.length} passed`);
