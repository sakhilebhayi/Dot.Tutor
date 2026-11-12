// Every GET route as every role (guest; learners: child, teen, adult; tutor; guardian; each operator role) at 390 and
// 1440: no 5xx, exactly one h1 on HTML pages, no horizontal overflow, no console errors (expected 4xx refusals
// aside), no CSP violation, no host but the app. Route parameters are filled from the data the journeys before this
// file created; the people who did the journeys sign in as themselves. A route nothing can fill is skipped and
// counted. Writes out/route-sweep-<role>-<width>.json and out/route-sweep-failures.json.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { browser, env, makePerson, one, world, externalHosts, closeBrowser, PASSWORD, evidenceLog } from '../lib/harness.mjs';

const ev = evidenceLog('09-route-sweep');

// Not pages: framework endpoints, sign-in plumbing, tokens nobody can guess, the realtime/poll endpoints.
const SKIP = [/^livewire/, /^storage\//, /^sanctum\//, /^up$/, /^two-factor-challenge$/, /^user\/two-factor/, /^user\/confirmed-password-status$/,
    /^team-invitations\//, /^reset-password\//, /^auth\/ecosystem$/, /^register$/, /^forgot-password$/, /^login$/, /^user\/confirm-password$/, /^_/, /\{token\}/, /\{path\}/, /\{filename\}/,
    /^logout$/, /^broadcasting\/auth$/, /^email\/verify/, /^api\/user$/];

function routes() {
    const out = execFileSync('php', ['artisan', 'route:list', '--json', '--method=GET'], { cwd: env.root, env: process.env, encoding: 'utf8' });
    return [...new Set(JSON.parse(out).map((r) => r.uri.replace(/^\/?/, '')))].filter((u) => !SKIP.some((re) => re.test(u)));
}

const roles = {};
const id = (q, p = []) => { try { return one(q, p)?.id ?? null; } catch { return null; } };

test.before(async () => {
    const lesson = one("select id, student_id, tutor_profile_id from tutor_sessions where status = 'completed' and payment_status = 'paid' order by id limit 1");
    assert.ok(lesson, 'run the journeys first: the sweep signs in as their people');
    const user = (uid) => { world('password', { id: uid }); return one('select id, email from users where id = ?', [uid]); };
    roles.guest = null;
    roles.adult_learner = user(lesson.student_id);
    roles.tutor = user(one('select user_id from tutor_profiles where id = ?', [lesson.tutor_profile_id]).user_id);
    const link = (minAge, maxAge) => one(`select l.learner_id, l.guardian_id from safety_guardian_links l join learner_profiles p on p.user_id = l.learner_id
        where l.status = 'active' and p.birth_month > date('now', '-${maxAge + 1} years') and p.birth_month <= date('now', '-${minAge} years') order by l.id limit 1`);
    const child = link(0, 12), teen = link(13, 17);
    assert.ok(child && teen, 'the safety journeys made a child and a teen with guardians');
    roles.child = user(child.learner_id);
    roles.teen = user(teen.learner_id);
    roles.guardian = user(child.guardian_id);
    for (const r of ['admin', 'academic_editor', 'verification_reviewer', 'safeguarding_officer', 'content_moderator', 'support_agent', 'finance']) roles[r] = makePerson('operator', r, { roles: [r] });
    roles._ids = { lesson: lesson.id, child: child.learner_id, adult: lesson.student_id, tutorProfile: lesson.tutor_profile_id };
});

/** A value for each route parameter, from the journeys' rows; the route decides which table a name means. */
function value(uri, name) {
    const ids = roles._ids;
    const course = id("select id from learning_courses where status = 'published' order by id");
    switch (name) {
        case 'level': return id('select academic_level_id id from community_rooms where academic_level_id is not null order by id') ?? id('select id from academic_levels order by id');
        case 'offering':
            if (uri.startsWith('rooms') || uri.includes('community')) return id('select curriculum_subject_level_id id from community_rooms where curriculum_subject_level_id is not null order by id');
            if (uri.startsWith('academic')) return id('select id from curriculum_subject_levels order by id');
            return id('select id from tutor_offerings where tutor_profile_id = ? order by id', [ids.tutorProfile]);
        case 'tutorSession': return ids.lesson;
        case 'session': return uri.startsWith('comms') ? ids.lesson : id('select id from community_sessions order by id');
        case 'tutorProfile': return ids.tutorProfile;
        case 'handle': return one('select public_handle from tutor_profiles where public_handle is not null')?.public_handle ?? null;
        case 'room': return id('select id from community_rooms order by id');
        case 'group': return id('select id from community_groups order by id');
        case 'post': return id('select id from community_posts order by id');
        case 'interaction': return id('select id from ai_interactions order by id desc');
        case 'type': return 'learning.resource';
        case 'id': return id("select id from learning_resources where status = 'published' order by id");
        case 'field': return 'description';
        case 'assessment': return id('select assessment_id id from learning_assignments order by id');
        case 'attempt': return id('select id from learning_attempts order by id');
        case 'bank': return id('select id from learning_question_banks order by id');
        case 'bundle': return id('select id from learning_bundles order by id');
        case 'course': return course;
        case 'lesson': return uri.includes('studio') ? id('select id from learning_lessons order by id') : id('select id from learning_lessons where course_id = ? order by id', [course]);
        case 'material': return id('select id from learning_materials where course_id = ? order by id', [course]) ?? id('select id from learning_materials order by id');
        case 'set': return id('select id from learning_flashcard_sets order by id');
        case 'resource':
            if (uri.startsWith('operator/academic')) return 'subjects';
            if (uri.includes('classroom')) return id('select id from lesson_resources order by id');
            return id("select id from learning_resources where status = 'published' order by id");
        case 'evidence': return id('select id from tutor_qualification_evidence order by id');
        case 'order': return one('select uuid from money_orders order by id')?.uuid ?? null;
        case 'batch': return id('select id from money_payout_batches order by id');
        case 'receipt': return id('select id from money_receipts order by id');
        case 'payout': return id('select id from money_payouts order by id');
        case 'code': return one('select code from learning_certificates order by id')?.code ?? null;
        case 'learner': return uri.startsWith('guardian') ? ids.child : ids.adult;
        case 'kind': return 'lesson';
        case 'item': return id("select id from learning_lessons where media_path is not null order by id");
        case 'incident': return id('select id from safety_incidents order by id');
        case 'report': return id('select id from safety_reports order by id');
        case 'conversation': return id('select id from safety_conversations order by id');
        case 'user': return ids.adult;
        case 'lessonResource': return id('select id from lesson_resources order by id');
        case 'attachment': return id('select id from safety_message_attachments order by id');
        case 'invitation': return id('select id from safety_guardian_invitations order by id');
        case 'check': return id('select id from safety_screening_checks order by id');
        case 'team': return id('select id from teams order by id');
        default: return null;
    }
}

const fill = (uri) => {
    let miss = false;
    const url = '/' + uri.replace(/\{(\w+)(\?)?\}/g, (_, k, optional) => { const v = value(uri, k); if (v == null) { if (optional) return ''; miss = true; return 'x'; } return String(v); });
    return miss ? null : url.replace(/\/$/, '') || '/';
};

async function contextFor(role, width) {
    const b = await browser();
    const ctx = await b.newContext({ baseURL: env.base, ignoreHTTPSErrors: true, viewport: { width, height: width === 390 ? 844 : 900 }, acceptDownloads: true, timezoneId: 'Africa/Johannesburg' });
    await ctx.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`)); });
    const user = roles[role];
    if (user) {
        const page = await ctx.newPage();
        await page.goto('/login');
        await page.fill('input[name=email]', user.email);
        await page.fill('input[name=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForLoadState('load');
        const ok = !new URL(page.url()).pathname.startsWith('/login');
        await page.close();
        if (!ok) throw new Error(`could not sign in as ${role}`);
    }
    return ctx;
}

async function sweep(role, width, urls) {
    const ctx = await contextFor(role, width);
    const page = await ctx.newPage();
    const errors = []; const requests = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('request', (r) => requests.push(r.url()));
    const report = []; const failures = [];
    for (const url of urls) {
        errors.length = 0;
        const start = requests.length;
        let resp;
        try { resp = await page.goto(url, { waitUntil: 'load', timeout: 30000 }); } catch (e) {
            if (/Download is starting/.test(e.message)) {
                const r = await page.request.get(url);
                if (r.status() >= 500) failures.push(`${role}@${width} ${url}: HTTP ${r.status()}`);
                report.push({ url, status: r.status(), type: 'download' });
                continue;
            }
            failures.push(`${role}@${width} ${url}: navigation failed ${e.message.slice(0, 120)}`);
            continue;
        }
        const status = resp.status();
        const type = (resp.headers()['content-type'] || '').split(';')[0];
        const row = { url, final: new URL(page.url()).pathname, status, type };
        if (status >= 500) failures.push(`${role}@${width} ${url}: HTTP ${status}`);
        if (type === 'text/html') {
            await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
            const m = await page.evaluate(() => {
                const overflow = document.documentElement.scrollWidth - window.innerWidth;
                const culprits = overflow > 1 ? [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1 && !(e.parentElement && e.parentElement.getBoundingClientRect().right > window.innerWidth + 1))
                    .slice(0, 3).map((e) => `${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''}${e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : ''}`) : [];
                return { h1: document.querySelectorAll('h1').length, overflow, culprits, csp: window.__csp || [] };
            }).catch(() => ({ h1: -1, overflow: 0, culprits: [], csp: [] }));
            Object.assign(row, { h1: m.h1, overflow: m.overflow });
            if (m.h1 !== 1 && m.h1 !== -1) failures.push(`${role}@${width} ${url} -> ${row.final}: ${m.h1} h1 (HTTP ${status})`);
            if (m.overflow > 1) failures.push(`${role}@${width} ${url} -> ${row.final}: horizontal overflow ${m.overflow}px (${m.culprits.join(', ')})`);
            if (m.csp.length) failures.push(`${role}@${width} ${url}: CSP ${[...new Set(m.csp)].join(', ').slice(0, 200)}`);
            const here = page.url();
            const errs = errors.filter((e) => !/status of (401|402|403|404|409|419|422|429)/.test(e))
                .filter((e) => { const m2 = e.match(/^Blocked script execution in '([^']+)' because the document's frame is sandboxed/); return !m2 || m2[1] === here; });
            if (errs.length) failures.push(`${role}@${width} ${url}: console ${JSON.stringify(errs).slice(0, 300)}`);
            const ext = externalHosts(requests.slice(start));
            if (ext.length) failures.push(`${role}@${width} ${url}: external hosts ${ext.join(', ')}`);
        }
        report.push(row);
    }
    await ctx.close();
    return { report, failures };
}

test('every GET route as every role at 390 and 1440', async (t) => {
    const all = routes();
    const urls = all.map(fill).filter(Boolean);
    const skipped = all.filter((u) => !fill(u));
    t.diagnostic(`${all.length} GET routes, ${urls.length} swept, ${skipped.length} skipped for want of a parameter value: ${skipped.join(', ')}`);
    const jobs = [];
    for (const role of Object.keys(roles).filter((r) => !r.startsWith('_'))) for (const width of [1440, 390]) jobs.push([role, width]);
    const failures = [];
    const counts = [];
    let next = 0;
    await Promise.all(Array.from({ length: 4 }, async () => {
        while (next < jobs.length) {
            const [role, width] = jobs[next++];
            const res = await sweep(role, width, urls);
            writeFileSync(join(env.out, `route-sweep-${role}-${width}.json`), JSON.stringify(res.report, null, 1));
            const st = res.report.reduce((a, r) => { const k = `${String(r.status)[0]}xx`; a[k] = (a[k] || 0) + 1; return a; }, {});
            counts.push(`${role}@${width}: ${res.report.length} loads ${JSON.stringify(st)}, ${res.failures.length} problems`);
            t.diagnostic(counts.at(-1));
            failures.push(...res.failures);
        }
    }));
    writeFileSync(join(env.out, 'route-sweep-failures.json'), JSON.stringify(failures, null, 1));
    ev.add('S1', 'Route sweep', `${all.length} GET routes (after framework and token routes), ${urls.length} swept as ${jobs.length / 2} roles at 390 and 1440 (${jobs.length} sweeps), ${skipped.length} skipped for want of a value (${skipped.join(', ') || 'none'}); ${failures.length} problems. ${counts.sort().join('; ')}`);
    assert.deepEqual(failures, [], `${failures.length} problems (out/route-sweep-failures.json)`);
});

test.after(async () => { await closeBrowser(); });
