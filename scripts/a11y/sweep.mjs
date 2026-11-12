// R-47 accessibility sweep (DEVELOPMENT ONLY; docs/R47_A11Y.md): axe-core (WCAG 2.0/2.1/2.2 A and AA plus best
// practice; AAA contrast added on the young-learner step) in real Chrome over every GET page of every role and age
// band (JSON-only endpoints under page paths, such as /learning/certificates, are not pages and are left out), at 390, 768 and 1440 px in the light and dark themes, plus the live classroom (two people joined with fake
// camera and microphone). Also records horizontal overflow, h1 count, duplicate ids and touch targets under 24 px
// (44 px on the young step).
//
//   sh scripts/a11y/serve.sh &                       # dev server on 8721 (demo database)
//   node scripts/a11y/sweep.mjs [--filter regex] [--vp 390,1440] [--theme dark] [--json out.json] [--ci]
import { BASE, IMPACT, HEIGHT, arg, has, axe, browser, facts, log, person, php, setTheme, sleep, writeJson, ROOT } from './lib.mjs';
import { readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const filter = arg('filter') ? new RegExp(arg('filter')) : null;
const VPS = (arg('vp') || '390,768,1440').split(',').map(Number);
const THEMES = (arg('theme') || 'light,dark').split(',');
const rows = [];

function print(row) {
    const worst = row.violations.reduce((m, v) => Math.max(m, IMPACT[v.impact] || 0), 0);
    const tag = row.error ? 'ERROR' : worst >= 3 ? 'FAIL ' : row.violations.length ? 'minor' : 'ok   ';
    const extra = `${row.overflowX ? ' [H-SCROLL]' : ''}${row.dupIds?.length ? ' [dup ids ' + row.dupIds.slice(0, 3).join(',') + ']' : ''}${row.h1 !== undefined && row.h1 !== 1 ? ` [h1=${row.h1}]` : ''}${row.smallCount ? ` [small ${row.smallCount}]` : ''}`;
    console.log(`${tag} ${row.theme.padEnd(5)} ${String(row.vp).padStart(4)} ${row.id.padEnd(44)} ${row.error || row.violations.map((v) => `${v.id}(${v.impact[0]}:${v.nodes})`).join(' ')}${extra}`);
}

async function capture(id, page, { vps = VPS, themes = THEMES } = {}) {
    if (filter && !filter.test(id)) return;
    for (const theme of themes) {
        await setTheme(page, theme);
        for (const vp of vps) {
            const row = { id, vp, theme, violations: [] };
            try {
                await page.setViewportSize({ width: vp, height: HEIGHT[vp] || 900 });
                await sleep(200);
                const young = await page.evaluate(() => document.documentElement.dataset.tdStep === 'young');
                row.violations = await axe(page, { enhanced: young });
                Object.assign(row, await facts(page));
            } catch (e) { row.error = String(e.message || e).split('\n')[0].slice(0, 180); }
            rows.push(row); print(row);
        }
    }
}

function pageList(i) {
    const list = {
        guest: [null, ['/', '/login', '/forgot-password', '/terms-of-service', '/privacy-policy', '/cookies', '/language', '/does-not-exist', '/reset-password/not-a-token?email=x%40example.test']],
        young: ['demo.grade3@example.test', ['/learn', '/find-a-tutor', '/learn/courses', '/learn/progress', '/learn/practice', '/learn/account', '/learn/profile', '/learn/purchases', '/rooms', '/comms/inbox', '/comms/preferences', '/safety/me', '/ai', `/t/${i.thandekaProfile}`, `/learning/courses/${i.course}`]],
        teen: ['demo.teen@example.test', ['/learn', '/rooms', `/rooms/${i.teenRoom}`, `/rooms/posts/${i.teenQuestion}`, '/safety/conversations', '/safety/me', '/ai', '/learn/progress', '/learn/courses']],
        matric: ['demo.matric@example.test', ['/learn', `/find-a-tutor?subject_id=${i.maths}`, `/t/${i.thandekaProfile}`, '/tutors', `/learn/book/${i.thandekaOffering}`, `/learn/lessons/${i.matricLesson}`, '/my/bookings',
            '/learn/courses', '/learning/courses', `/learning/courses/${i.course}`, '/learning/library',
            '/learn/practice', '/learn/progress', '/learn/purchases', '/learn/account', '/learn/profile', '/learn/welcome', '/comms/inbox', '/comms/preferences', '/safety/me', '/safety/conversations', '/classroom', '/ai', '/ai/describe', '/user/profile']],
        university: ['demo.university@example.test', ['/learn', '/learn/courses', '/rooms']],
        adult: ['demo.adult@example.test', ['/rooms', `/rooms/${i.adultRoom}`, `/rooms/posts/${i.adultQuestion}`, `/rooms/groups/${i.group}`, `/rooms/${i.tutorRoom}`, `/rooms/levels/${i.level}`]],
        learner: ['demo.learner@example.test', [`/learning/courses/${i.course}`, `/learning/courses/${i.course}/learn/${i.firstLesson}`, `/learning/assessments/${i.assessment}/take`]],
        tutor: ['demo.workspace.tutor@example.test', ['/teach', '/teach/bookings', '/teach/bookings?view=upcoming', '/teach/bookings?view=past', '/teach/calendar', '/teach/students', `/teach/students/${i.workspaceLearner}`,
            '/teach/offerings', '/teach/offerings/new', `/teach/offerings/${i.workspaceOffering}/edit`, '/teach/courses', '/teach/courses/new', '/teach/materials', '/teach/materials/assessments',
            '/teach/earnings', '/teach/payouts', '/teach/insights', '/teach/storefront', '/teach/storefront/promo', '/teach/profile', '/teach/rooms', '/money/earnings', '/tutor/profile', '/tutor/availability',
            '/my/bookings', '/classroom', '/ai/tutor', `/lessons/${i.workspaceConfirmed}/classroom`]],
        studio: ['demo.tutor@example.test', ['/learning/studio/courses', `/learning/studio/courses/${i.course}`, `/teach/materials/banks/${i.bank}`, `/teach/materials/assessments/${i.assessment}`]],
        newtutor: ['demo.new.tutor@example.test', ['/teach', '/teach/profile']],
        guardian: ['demo.ops.guardian@example.test', ['/guardian', `/guardian/children/${i.child}`, '/safety/guardian', '/comms/inbox']],
        ops: ['demo.ops.admin@example.test', ['/ops', '/ops/users', `/ops/users/${i.opsUser}`, '/ops/users/team', '/ops/verification', `/ops/verification/tutors/${i.pendingTutorProfile}`, '/ops/reviews', '/ops/reviews/bookings',
            `/ops/reviews/bookings/${i.disputedLesson}`, '/ops/safeguarding', '/ops/safeguarding/incidents', `/ops/safeguarding/incidents/${i.incident}`, `/ops/safeguarding/reports/${i.report}`, '/ops/safeguarding/minors',
            '/ops/safeguarding/screening', '/ops/safeguarding/integrity', '/ops/moderation', '/ops/support', `/ops/support/${i.conversation}`, '/ops/payments', `/ops/payments/orders/${i.order}`, '/ops/payouts',
            '/ops/academic', '/ops/academic/subjects', '/ops/academic/languages', '/ops/audit', '/money/ops', '/operator/academic', '/operator/subjects', '/operator/tutor-profiles', '/operator/verification',
            '/safety/operator/queue', '/safety/operator/incidents', '/ai/operator/log', '/rooms/moderation']],
        classroom: ['demo.classroom.learner@example.test', [`/lessons/${i.classroomLesson}/classroom`, '/classroom']],
    };
    return list;
}

async function pages(b, ids) {
    for (const [group, [email, paths]] of Object.entries(pageList(ids))) {
        if (filter && !paths.some((p) => filter.test(`${group}:${p}`))) continue;
        let p;
        try { p = await person(b, email); } catch (e) { rows.push({ id: group, vp: 0, theme: '-', violations: [], error: e.message }); console.log('ERROR', group, e.message); continue; }
        if (group === 'learner') { // the quiz and the lesson belong to a free course: enrol first, as a learner would
            await p.page.goto(`${BASE}/learning/courses/${ids.course}`);
            const enrol = p.page.locator('button:has-text("Enrol")');
            if (await enrol.count()) await Promise.all([p.page.waitForNavigation().catch(() => {}), enrol.first().click()]);
        }
        for (const path of paths) {
            const id = `${group}:${path}`;
            if (filter && !filter.test(id)) continue;
            const res = await p.page.goto(BASE + path).catch((e) => ({ status: () => 'ERR ' + e.message.split('\n')[0] }));
            const status = res?.status?.();
            if (status !== 200 && !(path === '/does-not-exist' && status === 404)) log('status', status, id, '->', p.page.url().replace(BASE, ''));
            await sleep(300);
            await capture(id, p.page);
        }
        if (p.errors.length) log(group, 'page errors', p.errors.slice(0, 6));
        await p.ctx.close();
    }
    // Error views that a running dev server does not reach (403, 419, 429, 500, 503), rendered to files.
    execFileSync('php', [join(ROOT, 'scripts/a11y/render-errors.php')], { cwd: ROOT, env: { ...process.env, APP_URL: BASE } });
    const g = await person(b, null);
    for (const code of [403, 419, 429, 500, 503]) {
        const file = join(ROOT, `storage/a11y/errors/${code}.html`);
        if (!existsSync(file)) continue;
        await g.page.goto(`${BASE}/login`);
        await g.page.setContent(readFileSync(file, 'utf8'), { waitUntil: 'load' }).catch(() => {});
        await capture(`error:${code}`, g.page);
    }
    await g.ctx.close();
}

/** The live classroom: the tutor and the learner both join (fake camera and microphone), then the room is judged. */
async function classroom(b, ids) {
    if (filter && !filter.test('classroom-live')) return;
    php('artisan', ['db:seed', '--class=DemoClassroomSeeder', '--force']);
    const tutor = await person(b, 'demo.classroom.tutor@example.test');
    const learner = await person(b, 'demo.classroom.learner@example.test');
    for (const p of [tutor, learner]) {
        await p.page.goto(`${BASE}/lessons/${ids.classroomLesson}/classroom`);
        await sleep(1500);
        const join = p.page.locator('[data-cr-join], button:has-text("Join")').first();
        if (await join.count()) await join.click().catch((e) => log('join click', e.message.split('\n')[0]));
    }
    await sleep(5000);
    await capture('classroom-live:learner', learner.page);
    await capture('classroom-live:tutor', tutor.page, { vps: [390, 1440] });
    for (const p of [tutor, learner]) { if (p.errors.length) log('classroom page errors', p.errors.slice(0, 4)); await p.ctx.close(); }
}

const ids = JSON.parse(php('scripts/a11y/ids.php'));
const b = await browser();
try {
    await pages(b, ids);
    await classroom(b, ids);
} finally { await b.close(); }

const path = writeJson(arg('json', 'sweep.json'), rows);
const serious = rows.filter((r) => r.violations.some((v) => IMPACT[v.impact] >= 3));
const all = rows.flatMap((r) => r.violations);
const byRule = {};
for (const r of rows) for (const v of r.violations) { byRule[v.id] ??= { impact: v.impact, states: new Set(), nodes: 0 }; byRule[v.id].states.add(r.id); byRule[v.id].nodes += v.nodes; }
console.log('\nRules:');
for (const [id, x] of Object.entries(byRule).sort((a, c) => IMPACT[c[1].impact] - IMPACT[a[1].impact])) console.log(`  ${id.padEnd(30)} ${x.impact.padEnd(9)} ${String(x.nodes).padStart(5)} nodes in ${x.states.size} pages: ${[...x.states].slice(0, 6).join(', ')}`);
const nodes = (list) => list.reduce((a, v) => a + v.nodes, 0);
console.log(`\n${rows.length} page states, ${rows.filter((r) => r.error).length} errors, ${serious.length} with serious/critical, ${nodes(all)} violating nodes (${nodes(all.filter((v) => IMPACT[v.impact] >= 3))} serious/critical), ${rows.filter((r) => r.overflowX).length} with horizontal scroll, ${rows.filter((r) => r.smallCount).length} with small targets. Report: ${path}`);
if (has('ci') && (serious.length || rows.some((r) => r.error))) process.exit(1);
