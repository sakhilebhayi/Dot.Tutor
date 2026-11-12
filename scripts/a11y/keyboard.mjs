// R-47 a11y (DEVELOPMENT ONLY; docs/R47_A11Y.md): keyboard-only checks in real Chrome with real key events.
//   node scripts/a11y/keyboard.mjs [--vp 390] [--only pages,journeys]
// Pages: the skip link is the first stop and moves focus to the content; a full Tab walk ends or wraps without a trap;
// every stop is named, shows a focus indicator, and is not hidden under the sticky bars (WCAG 2.4.11).
// Journeys, by keyboard only (Tab, Shift+Tab, arrows, Space, Enter, typing): book a lesson, take a quiz, post a question,
// join a classroom from the pre-join screen, a guardian approves a request, an operator releases a held post.
import { BASE, arg, browser, log, person, php, sleep, writeJson } from './lib.mjs';

const vp = Number(arg('vp', 390));
const only = arg('only', 'pages,journeys').split(',');
const results = [];
const check = (name, ok, info = '') => { results.push({ name, ok: !!ok, info }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  [' + String(info).slice(0, 240) + ']' : ''}`); };

const ACTIVE = `(() => { const a = document.activeElement; if (!a || a === document.body) return { tag: 'body' };
    const r = a.getBoundingClientRect(); const cs = getComputedStyle(a);
    const name = (a.getAttribute('aria-label') || (a.getAttribute('aria-labelledby') && a.getAttribute('aria-labelledby').split(' ').map((id) => document.getElementById(id)?.textContent || '').join(' ')) || a.labels?.[0]?.textContent || a.getAttribute('title') || a.textContent || a.value || a.getAttribute('placeholder') || '').trim().replace(/\\s+/g, ' ');
    const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2) || (cs.boxShadow && cs.boxShadow !== 'none');
    const pts = [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 3, r.top + 3], [r.right - 3, r.bottom - 3], [r.left + 3, r.bottom - 3], [r.right - 3, r.top + 3]];
    const visible = pts.some(([x, y]) => { if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) return false; const hit = document.elementFromPoint(x, y); return hit && (hit === a || a.contains(hit) || hit.contains(a) || (a.labels && [...a.labels].some((l) => l.contains(hit)))); });
    return { href: a.getAttribute('href') || '', o: cs.outlineStyle + ' ' + cs.outlineWidth + ' ' + cs.boxShadow.slice(0, 20), tag: a.tagName.toLowerCase(), type: a.type || '', cls: typeof a.className === 'string' ? a.className.trim().split(/\\s+/)[0] : '', id: a.id, name: name.slice(0, 50), ring, visible, w: r.width, h: r.height };
})()`;
const label = (a) => `${a.tag}${a.id ? '#' + a.id : ''}${a.cls ? '.' + a.cls : ''}[${a.name || ''}]`;
const reset = (page) => page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); const b = document.body; b.tabIndex = -1; b.focus(); b.removeAttribute('tabindex'); });

async function walk(page, id) {
    await reset(page);
    await page.keyboard.press('Tab');
    const first = await page.evaluate(ACTIVE);
    const isSkip = first.cls === 'td-skip' || /skip/i.test(first.name);
    check(`${id}: first Tab stop is the skip link`, isSkip, label(first));
    if (isSkip) {
        await page.keyboard.press('Enter'); await sleep(150);
        const after = await page.evaluate(() => { const a = document.activeElement; return a?.id || a?.tagName; });
        check(`${id}: skip link moves focus to the content`, /main/i.test(after), after);
    }
    await reset(page);
    const stops = [];
    for (let i = 0; i < 200; i++) {
        await page.keyboard.press('Tab');
        let a = await page.evaluate(ACTIVE);
        if (!a.visible && a.tag !== 'body') { await sleep(300); a = await page.evaluate(ACTIVE); }
        if (a.tag === 'body') { stops.push(a); break; }
        if (stops.length > 2 && label(a) === label(stops[0])) break;
        stops.push(a);
    }
    const real = stops.filter((s) => s.tag !== 'body');
    check(`${id}: Tab walk ends or wraps without a trap`, stops.length < 200, `${real.length} stops`);
    const unnamed = real.filter((s) => !s.name && !['input', 'select', 'textarea'].includes(s.tag));
    check(`${id}: every stop has a name`, unnamed.length === 0, unnamed.map(label).slice(0, 4).join(' '));
    const noRing = real.filter((s) => !s.ring && s.tag !== 'iframe');
    check(`${id}: every stop shows a focus indicator`, noRing.length === 0, noRing.map((s) => label(s) + ' {' + s.o + '}').slice(0, 4).join(' '));
    const hidden = real.filter((s) => !s.visible && s.w > 0);
    check(`${id}: no focused control is hidden (2.4.11)`, hidden.length === 0, hidden.map(label).slice(0, 4).join(' '));
    const zero = real.filter((s) => s.w === 0 || s.h === 0);
    check(`${id}: no invisible Tab stops`, zero.length === 0, zero.map(label).slice(0, 4).join(' '));
}

/** Tab (or Shift+Tab) until the focused control's name matches, max steps; returns what is focused. */
async function tabTo(page, re, { max = 120, back = false, tag = null, href = null } = {}) {
    for (let i = 0; i < max; i++) {
        await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
        const a = await page.evaluate(ACTIVE);
        if (re.test(a.name) && (!tag || tag === a.tag) && (!href || href.test(a.href))) return a;
    }
    return null;
}

const PAGES = {
    guest: [null, ['/', '/login']],
    young: ['demo.grade3@example.test', ['/learn', '/learn/progress']],
    matric: ['demo.matric@example.test', ['/learn', '/find-a-tutor?subject_id=SUBJECT', '/t/TUTOR', '/learn/book/OFFERING', '/learn/courses', '/comms/inbox']],
    adult: ['demo.adult@example.test', ['/rooms/ROOM', '/rooms/ROOM/ask']],
    tutor: ['demo.workspace.tutor@example.test', ['/teach', '/teach/bookings', '/teach/earnings', '/teach/calendar']],
    guardian: ['demo.ops.guardian@example.test', ['/guardian', '/guardian/children/CHILD']],
    ops: ['demo.ops.admin@example.test', ['/ops', '/ops/moderation', '/ops/users', '/ops/payments']],
    classroom: ['demo.classroom.learner@example.test', ['/lessons/LESSON/classroom']],
};

const ids = JSON.parse(php('scripts/a11y/ids.php'));
const fill = (p) => p.replace('SUBJECT', ids.maths).replace('TUTOR', ids.thandekaProfile).replace('OFFERING', ids.thandekaOffering).replace('ROOM', ids.adultRoom).replace('CHILD', ids.child).replace('LESSON', ids.classroomLesson);

const b = await browser();
try {
    if (only.includes('pages')) {
        php('artisan', ['db:seed', '--class=DemoClassroomSeeder', '--force']);
        for (const [group, [email, paths]] of Object.entries(PAGES)) {
            if (arg('group') && arg('group') !== group) continue;
            const p = await person(b, email, { width: vp });
            for (const path of paths.map(fill)) { await p.page.goto(BASE + path); await sleep(700); await walk(p.page, `${vp} ${group}:${path}`); }
            await p.ctx.close();
        }
    }
    if (only.includes('journeys')) {
        // 1. Book a lesson: from the tutor's storefront to a requested lesson.
        {
            const p = await person(b, 'demo.matric@example.test', { width: vp });
            await p.page.goto(`${BASE}/t/${ids.thandekaProfile}`); await sleep(600); await reset(p.page);
            const link = await tabTo(p.page, /book|choose a time|request/i, { tag: 'a' });
            check('book: a Book link is reachable by Tab on the storefront', link, link ? label(link) : 'not found');
            if (link) {
                await p.page.keyboard.press('Enter'); await sleep(800);
                // The storefront's Book button jumps to its offerings; the offering's own link opens the booking page.
                if (!/\/learn\/book\//.test(p.page.url())) {
                    const offering = await tabTo(p.page, /./, { tag: 'a', href: /\/learn\/book\//, max: 60 });
                    check('book: from the offerings, Tab reaches a lesson to book', offering, offering ? label(offering) : '');
                    if (offering) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(500); }
                }
            }
            const onBook = /\/learn\/book\//.test(p.page.url());
            check('book: Enter opens the booking page', onBook, p.page.url().replace(BASE, ''));
            if (onBook) {
                await reset(p.page);
                const radio = await tabTo(p.page, /./, { tag: 'input' });
                const time = await p.page.evaluate(ACTIVE);
                check('book: Tab reaches the first open time (a radio)', time.type === 'radio', label(time));
                await p.page.keyboard.press('ArrowRight'); await p.page.keyboard.press('Space');
                const picked = await p.page.evaluate(() => document.querySelector('input[name=starts_at]:checked')?.value);
                check('book: an arrow key and Space pick a time', !!picked, picked);
                const submit = await tabTo(p.page, /request this lesson/i);
                check('book: Tab reaches "Request this lesson"', submit, submit ? label(submit) : '');
                if (submit) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800); }
                check('book: Enter sends the request and opens the lesson', /\/learn\/lessons\/\d+/.test(p.page.url()), p.page.url().replace(BASE, ''));
                void radio;
            }
            await p.ctx.close();
        }
        // 2. Take a quiz.
        {
            const p = await person(b, 'demo.learner@example.test', { width: vp });
            // The quiz belongs to a course: enrol by keyboard first (free course).
            await p.page.goto(`${BASE}/learning/courses/${ids.course}`); await sleep(500); await reset(p.page);
            const enrol = await tabTo(p.page, /^enrol/i, { tag: 'button', max: 60 });
            if (enrol) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(600); }
            check('quiz: "Enrol" on the course is reachable by Tab (or already enrolled)', enrol || !(await p.page.locator('button:has-text("Enrol")').count()), enrol ? label(enrol) : '');
            await p.page.goto(`${BASE}/learning/assessments/${ids.assessment}/take`); await sleep(600);
            const status = p.page.url().replace(BASE, '');
            await reset(p.page);
            const start = await tabTo(p.page, /start|begin|try|continue/i, { tag: 'button', max: 60 });
            if (start) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800); await reset(p.page); }
            check('quiz: Tab reaches "Start" and Enter starts the attempt', start, start ? label(start) : status);
            const first = await tabTo(p.page, /./, { tag: 'input' });
            check('quiz: Tab reaches the first answer', first, status + ' ' + (first ? label(first) : 'no input'));
            if (first) {
                if (first.type === 'radio') await p.page.keyboard.press('Space');
                const second = await tabTo(p.page, /./, { tag: 'input', max: 20 });
                if (second && second.type !== 'radio' && second.type !== 'checkbox') await p.page.keyboard.type('3');
                const submit = await tabTo(p.page, /submit|finish|check|hand in/i, { max: 30 });
                check('quiz: Tab reaches the submit button', submit, submit ? label(submit) : '');
                if (submit) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800); }
                const result = await p.page.evaluate(() => document.querySelector('h1')?.textContent?.trim());
                check('quiz: Enter submits and shows the result', /\/attempts\/\d+|result/i.test(p.page.url()) || /result|score|you got/i.test(result || ''), `${p.page.url().replace(BASE, '')} h1="${result}"`);
            }
            await p.ctx.close();
        }
        // 3. Post a question in a room.
        {
            const p = await person(b, 'demo.adult@example.test', { width: vp });
            await p.page.goto(`${BASE}/rooms/${ids.adultRoom}`); await sleep(600); await reset(p.page);
            const ask = await tabTo(p.page, /ask a question|ask/i, { tag: 'a' });
            check('ask: "Ask a question" is reachable by Tab in the room', ask, ask ? label(ask) : '');
            if (ask) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(500); }
            await reset(p.page);
            const title = await tabTo(p.page, /title|question/i, { tag: 'input', max: 60 });
            check('ask: Tab reaches the question title', title, title ? label(title) : p.page.url());
            if (title) {
                await p.page.keyboard.type('Keyboard check: how do I complete the square?');
                const body = await tabTo(p.page, /./, { tag: 'textarea', max: 15 });
                if (body) await p.page.keyboard.type('I get lost after dividing by the leading coefficient. A worked example would help.');
                const submit = await tabTo(p.page, /post|ask|send|share/i, { tag: 'button', max: 30 });
                check('ask: Tab reaches the post button', submit, submit ? label(submit) : '');
                if (submit) { await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800); }
                const text = await p.page.evaluate(() => document.body.innerText);
                check('ask: Enter posts the question', /Keyboard check: how do I complete the square/.test(text) || /held|review/i.test(text), p.page.url().replace(BASE, ''));
            }
            await p.ctx.close();
        }
        // 4. Classroom pre-join: preview controls and Join by keyboard.
        {
            php('artisan', ['db:seed', '--class=DemoClassroomSeeder', '--force']);
            const p = await person(b, 'demo.classroom.learner@example.test', { width: vp });
            await p.page.goto(`${BASE}/lessons/${ids.classroomLesson}/classroom`); await sleep(1500); await reset(p.page);
            const join = await tabTo(p.page, /join/i, { tag: 'button', max: 60 });
            check('classroom: the Join button is reachable by Tab on the pre-join screen', join, join ? label(join) : '');
            if (join) { await p.page.keyboard.press('Enter'); await sleep(3500); }
            const live = await p.page.evaluate(() => { const l = document.querySelector('[data-cr-live], .cr-live, #cr-live'); return !!l && !l.hidden; });
            check('classroom: Enter joins the lesson', live, await p.page.evaluate(() => document.querySelector('[role=status]')?.textContent?.trim()?.slice(0, 80)));
            if (live) {
                await reset(p.page);
                const stops = [];
                for (let i = 0; i < 60; i++) { await p.page.keyboard.press('Tab'); const a = await p.page.evaluate(ACTIVE); if (a.tag === 'body' || (stops.length > 2 && label(a) === label(stops[0]))) break; stops.push(a); }
                const unnamed = stops.filter((s) => !s.name && !['input', 'select', 'textarea'].includes(s.tag));
                check('classroom: every live control reached by Tab has a name', unnamed.length === 0, `${stops.length} stops; unnamed: ${unnamed.map(label).join(' ')}`);
                check('classroom: mic, camera and leave controls are reachable', ['mic|microphone|mute', 'camera|video', 'leave|end'].every((w) => stops.some((s) => new RegExp(w, 'i').test(s.name))), stops.map((s) => s.name).slice(0, 14).join(' | '));
            }
            await p.ctx.close();
        }
        // 5. Guardian approves a request.
        {
            const p = await person(b, 'demo.ops.guardian@example.test', { width: vp });
            await p.page.goto(`${BASE}/guardian`); await sleep(600); await reset(p.page);
            const approve = await tabTo(p.page, /^approve/i, { tag: 'button' });
            check('guardian: "Approve" is reachable by Tab', approve, approve ? label(approve) : 'no pending request on the page');
            if (approve) {
                const before = await p.page.locator('button:has-text("Approve")').count();
                await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800);
                const after = await p.page.locator('button:has-text("Approve")').count();
                const status = await p.page.evaluate(() => document.querySelector('[role=status], .td-toast, .td-notice')?.textContent?.trim().replace(/\s+/g, ' ').slice(0, 80));
                check('guardian: Enter records the approval', after < before, `${before} -> ${after}; "${status}"`);
            }
            await p.ctx.close();
        }
        // 6. Operator releases a held post from the moderation queue.
        {
            const p = await person(b, 'demo.ops.admin@example.test', { width: vp });
            await p.page.goto(`${BASE}/ops/moderation`); await sleep(600); await reset(p.page);
            const release = await tabTo(p.page, /^release/i, { tag: 'button' });
            check('operator: "Release" on a held post is reachable by Tab', release, release ? label(release) : 'nothing held');
            if (release) {
                const before = await p.page.locator('button:has-text("Release")').count();
                await Promise.all([p.page.waitForNavigation().catch(() => {}), p.page.keyboard.press('Enter')]); await sleep(800);
                const afterCount = await p.page.locator('button:has-text("Release")').count();
                check('operator: Enter releases the post', afterCount < before || /released/i.test(await p.page.evaluate(() => document.body.innerText)), `${before} -> ${afterCount}`);
            }
            await p.ctx.close();
        }
    }
} finally { await b.close(); }

writeJson(`keyboard-${vp}.json`, results);
const failed = results.filter((r) => !r.ok);
log(`${results.length} checks, ${failed.length} failed`);
process.exit(failed.length ? 1 : 0);
