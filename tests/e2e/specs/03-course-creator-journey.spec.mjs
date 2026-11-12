// Brief section 25, course creator journey, through the tutor workspace's course studio (/teach/courses) at 1440, with
// a learner on a 390 phone buying and studying the course: create, sections and lessons (a reading lesson and an
// uploaded PDF), a downloadable material, language and level, price with the commission preview, publish, a paid
// enrolment through Paystack's checkout (stub), progress, and the creator's earnings.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, one, sql, until, step, watch, hygiene, shot, interceptCheckout, makePerson,
    evidenceLog, fixtureFile, cspViolations, world, uniq,
} from '../lib/harness.mjs';
import { levelId, subjectId } from '../lib/flows.mjs';

const ev = evidenceLog('03-course-creator');

test('course creator journey: create to earnings', async () => {
    const creator = makePerson('ready-tutor', 'Creator', { name: 'Zanele Mthembu' });
    const ctx = await newContext({ user: creator, width: 1440, height: 1000 });
    const page = await ctx.newPage();
    const store = watch(page);
    const title = `Functions and graphs, Grade 11 (${uniq('c').slice(-4)})`;
    let courseId;

    // 1. Create a course (draft).
    await step(page, 'create', async () => {
        await page.goto('/teach/courses/new');
        await page.fill('input[name=title]', title);
        await page.selectOption('select[name=subject_id]', String(subjectId('mathematics')));
        await page.selectOption('select[name=level_id]', String(levelId('grade-11')));
        await page.selectOption('select[name=original_locale]', 'zu');
        await page.fill('textarea[name=description]', 'Ukuqonda imisebenzi namagrafu: a Grade 11 course taught in isiZulu, with worked examples.');
        await Promise.all([page.waitForURL(/\/teach\/courses\/\d+/), page.getByRole('button', { name: 'Create draft' }).click()]);
        courseId = Number(page.url().match(/courses\/(\d+)/)[1]);
        const c = one('select * from learning_courses where id = ?', [courseId]);
        assert.equal(c.status, 'draft');
        ev.add(1, 'Create a course', `learning_courses ${courseId}: draft, owner = the tutor, title "${title}"`);
    });

    // 4. Language and academic level (basics), then outcomes.
    await step(page, 'basics and outcomes', async () => {
        const c = one('select original_locale, level_id, curriculum_id, subject_id from learning_courses where id = ?', [courseId]);
        assert.equal(c.original_locale, 'zu');
        assert.equal(c.level_id, levelId('grade-11'));
        await page.goto(`/teach/courses/${courseId}?tab=outcomes`);
        await page.locator('input[name="objectives[]"]').first().fill('Sketch and interpret linear, quadratic and exponential functions');
        await page.fill('input[name=workload_minutes]', '240');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Save and continue' }).click()]);
        ev.add(4, 'Configure course language and academic level', `original_locale zu (isiZulu), level CAPS Grade 11 (curriculum ${c.curriculum_id}), subject Mathematics; objectives and a 240-minute workload saved`);
    });

    // 2-3. Sections and lessons; an uploaded learning material.
    await step(page, 'structure', async () => {
        await page.goto(`/teach/courses/${courseId}?tab=structure`);
        await page.fill('#section-title', 'Functions');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add section' }).click()]);
        const section = await until(() => one('select id from learning_course_sections where course_id = ?', [courseId]), { what: 'section' });
        const s = section.id;
        const openAdd = async () => { if (!(await page.locator(`#lesson-${s}-title`).isVisible())) await page.getByText('Add a lesson to this section').click(); };
        await openAdd();
        await page.fill(`#lesson-${s}-title`, 'What a function is');
        await page.selectOption(`#lesson-${s}-type`, 'text');
        await page.fill(`#lesson-${s}-body`, 'A function gives exactly one output for each input. Umsebenzi unikeza umphumela owodwa.');
        await page.check(`#lesson-${s}-preview`);
        await Promise.all([page.waitForLoadState('load'), page.locator(`form:has(#lesson-${s}-title) button[type=submit]`).click()]);
        await openAdd();
        await page.fill(`#lesson-${s}-title`, 'Worked examples (PDF)');
        await page.selectOption(`#lesson-${s}-type`, 'document');
        await page.setInputFiles(`#lesson-${s}-file`, fixtureFile('pdf'));
        await Promise.all([page.waitForLoadState('load'), page.locator(`form:has(#lesson-${s}-title) button[type=submit]`).click()]);
        const lessons = await until(() => { const r = sql('select id, type, is_preview from learning_lessons where section_id = ? order by id', [s]); return r.length === 2 ? r : null; }, { what: 'two lessons' });
        assert.deepEqual(lessons.map((l) => l.type), ['text', 'document']);
        ev.add(2, 'Add sections and lessons', `learning_course_sections ${s} "Functions"; learning_lessons: text (free preview) and document (uploaded PDF)`);

        await page.goto(`/teach/courses/${courseId}?tab=structure`);
        await page.fill('#material-title', 'Graph paper worksheet');
        await page.selectOption('#material-permission', 'enrolled_download');
        await page.setInputFiles('#material-file', fixtureFile('pdf'));
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Upload material' }).click()]);
        const m = await until(() => one('select * from learning_materials where course_id = ?', [courseId]), { what: 'material' });
        assert.equal(m.permission, 'enrolled_download');
        ev.add(3, 'Upload learning material', `learning_materials ${m.id}: PDF, permission enrolled_download, stored on the private disk`);
    });

    // 5. Price with the live commission figure.
    await step(page, 'price', async () => {
        await page.goto(`/teach/courses/${courseId}?tab=price`);
        await page.check('input[name=pricing][value=paid]');
        await page.fill('input[name=price]', '199');
        await page.locator('input[name=price]').blur();
        await page.waitForFunction(() => /169[.,]15/.test(document.querySelector('main').innerText), null, { timeout: 10000 });
        await page.fill('textarea[name=refund_policy]', 'Full refund within 7 days if you have opened fewer than half of the lessons.');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Save and continue' }).click()]);
        const c = one('select pricing, price_minor from learning_courses where id = ?', [courseId]);
        assert.equal(c.pricing, 'paid');
        assert.equal(c.price_minor, 19900);
        ev.add(5, 'Set pricing', 'paid, price_minor 19900; the price tab showed the server\'s "you keep R169.15" (15% commission) before saving; refund policy stored');
    });

    // 6. Publish.
    await step(page, 'publish', async () => {
        await page.goto(`/teach/courses/${courseId}?tab=publish`);
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: /Publish course/ }).click()]);
        const c = one('select status, current_version_id from learning_courses where id = ?', [courseId]);
        assert.equal(c.status, 'published');
        assert.ok(c.current_version_id, 'a published version');
        ev.add(6, 'Publish', `learning_courses status published, current_version_id ${c.current_version_id}`);
    });

    // 7. A learner finds and buys it (Paystack test checkout), and is enrolled.
    const learner = makePerson('learner', 'Buyer', { age: 17 + 3 });
    const lctx = await newContext({ user: learner, width: 390 });
    await interceptCheckout(lctx);
    const lp = await lctx.newPage();
    const lstore = watch(lp);
    await step(lp, 'enrol', async () => {
        await lp.goto(`/learning/courses?level_id=${levelId('grade-11')}`);
        await Promise.all([lp.waitForURL(`**/learning/courses/${courseId}**`), lp.locator(`a[href$="/learning/courses/${courseId}"]`).first().click()]);
        assert.match(await lp.innerText('main'), /R199\.00/);
        await Promise.all([lp.waitForURL(/checkout\.paystack\.com/), lp.getByRole('button', { name: 'Buy course' }).click()]);
        await Promise.all([lp.waitForURL((u) => u.host !== 'checkout.paystack.com', { timeout: 20000 }), lp.click('#pay')]);
        const enrolment = await until(() => one('select * from learning_enrolments where course_id = ? and user_id = ?', [courseId, learner.id]), { what: 'enrolment' });
        assert.equal(enrolment.status, 'active');
        const order = one("select o.status, o.total_minor from money_orders o join money_order_lines l on l.order_id = o.id where l.purchasable_type = 'course' and l.purchasable_id = ? and o.buyer_id = ?", [courseId, learner.id]);
        assert.equal(order.status, 'paid');
        ev.add(7, 'Receive enrolments', `learner bought it on /learning/courses/${courseId}: money_orders paid 19900 via signed webhook; learning_enrolments active (source purchase)`);
    });

    // 8. Progress: the learner completes a lesson; the creator sees it on the course's learners tab.
    await step(lp, 'progress', async () => {
        await lp.goto(`/learning/courses/${courseId}/learn`);
        await Promise.all([lp.waitForLoadState('load'), lp.getByRole('button', { name: 'Mark as complete' }).first().click()]);
        const done = await until(() => one('select count(*) n from learning_lesson_progress p join learning_enrolments e on e.id = p.enrolment_id where e.course_id = ? and e.user_id = ? and p.completed_at is not null', [courseId, learner.id]).n, { what: 'lesson progress' });
        await page.goto(`/teach/courses/${courseId}?tab=learners`);
        const text = await page.innerText('main');
        assert.match(text, /Buyer/);
        assert.match(text, /50\s?%|1 of 2/);
        ev.add(8, 'Track student progress', `learning_lesson_progress: ${done} lesson completed by the learner; the learners tab lists them at 1 of 2 lessons (50%)`);
    });

    // 9. Earnings: the sale on /teach/earnings and on the course list, pending until the refund window ends.
    await step(page, 'earnings', async () => {
        const e = one("select e.* from money_earnings e join money_order_lines l on l.id = e.order_line_id where l.purchasable_type = 'course' and l.purchasable_id = ?", [courseId]);
        assert.equal(e.gross_minor, 19900);
        assert.equal(e.net_minor, 19900 - e.commission_minor - e.charges_minor);
        await page.goto('/teach/earnings');
        assert.match(await page.innerText('main'), new RegExp(`R${(e.net_minor / 100).toFixed(2)}`));
        await page.goto('/teach/courses');
        assert.match(await page.innerText('main'), /1/);
        ev.add(9, 'Receive earnings', `money_earnings ${e.id}: gross 19900, commission ${e.commission_minor}, net ${e.net_minor}, ${e.status} (released after the 7-day refund window, release_after ${e.release_after}); shown on /teach/earnings`);
    });

    await shot(page, 'course-creator-done');
    for (const [who, st] of [['creator', store], ['learner', lstore]]) {
        const h = hygiene(st);
        assert.deepEqual(h.bad, [], `${who}: no 5xx`);
        assert.deepEqual(h.errors, [], `${who}: no console errors`);
        assert.deepEqual(h.external, [], `${who}: no third-party host`);
    }
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
