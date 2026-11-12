// The classroom agent's two-browser WebRTC check (tests/e2e/classroom/run.mjs), folded into the harness: a tutor at
// 1440 and a learner on a 390 phone with Chrome's fake camera and microphone. Evidence comes from
// RTCPeerConnection.getStats() through an init script that wraps RTCPeerConnection, independently of the app code.
// Honest limits: one machine, so the path is a host candidate; no TURN or STUN server (none on the internet here).
import test from 'node:test';
import assert from 'node:assert/strict';
import { newContext, closeBrowser, closeLeftovers, world, one, sql, makePerson, evidenceLog, shot, sleep } from '../lib/harness.mjs';

const ev = evidenceLog('08-classroom');

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

test('two browsers in a live lesson: media, chat, whiteboard, notes, end', async () => {
    const tutor = makePerson('ready-tutor', 'RoomTutor', { name: 'Classroom Tutor' });
    const learner = makePerson('learner', 'RoomLearner', { name: 'Classroom Learner', age: 20 });
    const lesson = world('confirmed-lesson', { profile_id: tutor.profile_id, learner_id: learner.id });
    const tctx = await newContext({ user: tutor, width: 1440, height: 1000, media: true });
    const lctx = await newContext({ user: learner, width: 390, height: 844, media: true });
    await tctx.addInitScript(PC_HOOK);
    await lctx.addInitScript(PC_HOOK);
    const tp = await tctx.newPage();
    const lp = await lctx.newPage();
    const errors = [];
    for (const [who, p] of [['tutor', tp], ['learner', lp]]) {
        p.on('pageerror', (e) => errors.push(`${who}: ${e.message}`));
        // A 409 after the tutor ends the lesson is the server's expected answer to the next poll.
        p.on('console', (m) => { if (m.type() === 'error' && !/status of 409/.test(m.text())) errors.push(`${who} console: ${m.text()}`); });
    }
    const checks = [];
    const check = (name, ok, detail = '') => { checks.push(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`); assert.ok(ok, `${name} ${detail}`); };

    const url = `/lessons/${lesson.id}/classroom`;
    await tp.goto(url);
    await lp.goto(url);
    await tp.waitForFunction(() => document.getElementById('cr-check-mic')?.textContent === 'Working', null, { timeout: 15000 });
    await lp.waitForFunction(() => document.getElementById('cr-check-cam')?.textContent === 'Working', null, { timeout: 15000 });
    const level = await tp.evaluate(async () => { let max = 0; for (let i = 0; i < 30; i++) { max = Math.max(max, Number(document.getElementById('cr-level').getAttribute('aria-valuenow'))); await new Promise((r) => setTimeout(r, 100)); } return max; });
    check('pre-join: fake camera and microphone found, the level meter moves', level > 0, `peak ${level}`);

    await tp.click('#cr-join');
    await lp.click('#cr-join');
    await tp.waitForSelector('#cr-live:not([hidden])', { timeout: 10000 });
    await lp.waitForSelector('#cr-live:not([hidden])', { timeout: 10000 });
    let connected = false;
    for (let i = 0; i < 30 && !connected; i++) {
        await sleep(1000);
        const [a, b] = [await stats(tp), await stats(lp)];
        connected = a.some((s) => s.state === 'connected') && b.some((s) => s.state === 'connected');
    }
    check('peer connection "connected" in both browsers', connected);
    await sleep(4000);
    const s1t = (await stats(tp))[0] || {}; const s1l = (await stats(lp))[0] || {};
    await sleep(3000);
    const s2t = (await stats(tp))[0] || {}; const s2l = (await stats(lp))[0] || {};
    check('tutor receives the learner\'s video', s2t.frames > s1t.frames && s2t.frames > 30, `${s1t.frames} -> ${s2t.frames} frames`);
    check('learner receives the tutor\'s video', s2l.frames > s1l.frames && s2l.frames > 30, `${s1l.frames} -> ${s2l.frames} frames`);
    check('audio flows both ways', s2t.audioIn > s1t.audioIn && s2l.audioIn > s1l.audioIn, `tutor in ${s2t.audioIn} B, learner in ${s2l.audioIn} B`);
    check('a nominated candidate pair succeeded', !!(s2t.pair && s2l.pair));

    await lp.click('#cr-mic');
    await tp.waitForFunction(() => /Microphone off/.test(document.getElementById('cr-people')?.textContent || ''), null, { timeout: 8000 });
    check('mute reaches the other side', true);
    await lp.click('#cr-mic');

    await tp.click('#cr-tab-chat-tab');
    await tp.fill('#cr-chat-input', 'Welcome. Open the worksheet.');
    await tp.click('#cr-chat-form button[type=submit]');
    await lp.click('#cr-tab-chat-tab');
    await lp.waitForFunction(() => /Welcome\. Open the worksheet\./.test(document.getElementById('cr-chat-log').textContent), null, { timeout: 8000 });
    check('chat reaches the learner', true);

    await tp.click('#cr-tab-board-tab');
    await sleep(400);
    const box = await tp.locator('#cr-canvas').boundingBox();
    await tp.mouse.move(box.x + 100, box.y + 100);
    await tp.mouse.down();
    for (let i = 1; i <= 10; i++) await tp.mouse.move(box.x + 100 + i * 30, box.y + 100 + i * 12);
    await tp.mouse.up();
    await tp.click('[data-tool=text]');
    await tp.mouse.click(box.x + 300, box.y + 250);
    await tp.fill('#cr-text-input', 'x^2 - 9 = (x - 3)(x + 3)');
    await tp.press('#cr-text-input', 'Enter');
    await lp.click('#cr-tab-board-tab');
    await lp.waitForFunction(() => /\(x - 3\)\(x \+ 3\)/.test(document.getElementById('cr-board-text').textContent) && /1 drawing/.test(document.getElementById('cr-board-text').textContent), null, { timeout: 8000 });
    const inked = await lp.evaluate(() => { const c = document.getElementById('cr-canvas'); const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 0) n++; return n; });
    check('whiteboard stroke and text reach the learner, ink on their canvas', inked > 500, `${inked} painted pixels`);

    await lp.click('#cr-tab-notes-tab');
    await lp.fill('#cr-notes', 'Homework: exercise 4.2');
    await tp.click('#cr-tab-notes-tab');
    await tp.waitForFunction(() => document.getElementById('cr-notes').value === 'Homework: exercise 4.2', null, { timeout: 10000 });
    check('shared notes reach the tutor', true);

    await lp.click('#cr-tab-video-tab');
    await sleep(800);
    const overflow = await lp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('no horizontal scroll at 390 px', overflow <= 0, `${overflow}px`);
    const small = await lp.evaluate(() => [...document.querySelectorAll('#cr-controls button, #cr-controls summary')].filter((b) => { const r = b.getBoundingClientRect(); return r.width && (r.width < 44 || r.height < 44); }).length);
    check('call controls at least 44 px on the phone', small === 0, `${small} small`);
    await shot(tp, 'classroom-tutor-1440');
    await shot(lp, 'classroom-learner-390');

    tp.on('dialog', (d) => d.accept());
    await Promise.all([tp.waitForURL(/\/classroom\/after$/, { timeout: 10000 }), tp.click('#cr-end')]);
    await lp.waitForSelector('#cr-notice:not([hidden])', { timeout: 10000 });
    const open = await lp.evaluate(() => (window.__pcs || []).filter((pc) => pc.connectionState !== 'closed').length);
    check('the learner is told the lesson ended and their peer connections are closed', open === 0, `${open} open`);
    const attendance = sql('select a.role from classroom_attendance a join classrooms c on c.id = a.classroom_id where c.tutor_session_id = ?', [lesson.id]);
    check('attendance on the record for both people', attendance.length >= 2, JSON.stringify(attendance));
    const room = one('select status, ended_at, ended_reason from classrooms where tutor_session_id = ?', [lesson.id]);
    check('room ended on the record', !!room.ended_at, room.ended_reason);
    check('no script errors in either page', errors.length === 0, errors.slice(0, 3).join(' | '));
    ev.add('C1', 'Live lesson in two browsers', checks.join('; '));
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
