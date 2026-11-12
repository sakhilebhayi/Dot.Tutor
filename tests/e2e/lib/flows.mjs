// Multi-step flows several specs share, each done through the app's own screens. Every helper asserts what the
// database must hold after it, so a spec that calls one gets that evidence for free.
import assert from 'node:assert/strict';
import { one, sql, until, newContext, signIn, world, stub, step, makePerson, fixtureFile } from './harness.mjs';

export const levelId = (code, curriculum = 'za-caps') => one('select l.id from academic_levels l join curricula c on c.id = l.curriculum_id where c.code = ? and l.code = ?', [curriculum, code]).id;
export const subjectId = (slug) => one('select id from subjects where slug = ?', [slug]).id;

/** "YYYY-MM-DD HH:MM:SS" in UTC, as the app stores timestamps. */
export const dbTime = (date) => date.toISOString().slice(0, 19).replace('T', ' ');

/** The first open time on the book page at least `days` days ahead (a radio value, ISO-8601). */
export async function firstSlotOn(page, days = 2) {
    const values = await page.locator('input[name=starts_at]').evaluateAll((els) => els.map((e) => e.value));
    const after = Date.now() + days * 86400000;
    const hit = values.find((v) => new Date(v).getTime() >= after && new Date(v).getUTCHours() >= 8 && new Date(v).getUTCHours() <= 14);
    assert.ok(hit, `an open time ${days} days ahead (${values.length} offered)`);
    return hit;
}

/** The tutor accepts a request on /teach/bookings (Requests view). */
export async function acceptOnTeach(tctx, lessonId) {
    const page = await tctx.newPage();
    await page.goto('/teach/bookings?view=requests');
    await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/teach/bookings/${lessonId}/accept"] button[type=submit]`).click()]);
    await until(() => one('select status from tutor_sessions where id = ?', [lessonId]).status !== 'pending', { what: 'the tutor\'s decision' });
    await page.close();
}

/**
 * Pays an accepted lesson from the learner's lesson page: Pay -> Paystack's hosted checkout (routed to the local
 * stub) -> Pay -> back to the app. Returns the order, asserted paid, with one verified webhook.
 */
export async function payOnLessonPage(page, lessonId, { outcome = 'pay' } = {}) {
    await page.goto(`/learn/lessons/${lessonId}`);
    await Promise.all([page.waitForURL(/checkout\.paystack\.com/), page.locator(`form[action$="/learn/lessons/${lessonId}/pay"] button[type=submit]`).click()]);
    assert.match(await page.innerText('h1'), /Pay R\d+\.\d\d/);
    await Promise.all([page.waitForURL((u) => u.host !== 'checkout.paystack.com', { timeout: 20000 }), page.click(outcome === 'pay' ? '#pay' : '#decline')]);
    const line = one("select o.* from money_orders o join money_order_lines l on l.order_id = o.id where l.purchasable_type = 'booking' and l.purchasable_id = ? order by o.id desc limit 1", [lessonId]);
    assert.ok(line, 'a money order holds the lesson');
    if (outcome !== 'pay') return line;
    const order = await until(() => { const o = one('select * from money_orders where id = ?', [line.id]); return o.status === 'paid' ? o : null; }, { what: 'order paid' });
    const payment = one("select * from money_payments where order_id = ? and status = 'paid'", [order.id]);
    assert.ok(payment?.verified_at, 'the payment was verified with Paystack server-side');
    const hooks = (await stub()).webhooks.filter((w) => w.reference === payment.reference);
    assert.ok(hooks.length >= 1 && hooks.every((w) => w.status === 200), `signed webhook accepted (${JSON.stringify(hooks.map((h) => h.status))})`);
    return order;
}

/**
 * The lesson in the classroom: the lesson is moved to start in five minutes (the room opens ten minutes before),
 * the tutor (1440) and the learner (their page) join with Chrome's fake camera and microphone, the peer connection
 * reaches "connected" in both browsers (RTCPeerConnection.getStats, independent of the app's code), the tutor
 * publishes a summary and ends the lesson; with the start moved into the past, the tutor marks it completed.
 */
export async function attendLesson({ lessonId, tutor = null, tutorContext = null, learnerPage }) {
    const now = Date.now();
    world('update', { table: 'tutor_sessions', where: { id: lessonId }, set: { starts_at: dbTime(new Date(now + 5 * 60000)) } });
    const tctx = tutorContext ?? await newContext({ width: 1440, height: 1000, media: true });
    if (tutorContext) await tctx.grantPermissions(['camera', 'microphone']);
    else await signIn(tctx, tutor);
    const tpage = await tctx.newPage();
    const hook = () => {
        const Orig = window.RTCPeerConnection; window.__pcs = [];
        function Wrapped(...a) { const pc = new Orig(...a); window.__pcs.push(pc); return pc; }
        Wrapped.prototype = Orig.prototype; Object.setPrototypeOf(Wrapped, Orig); window.RTCPeerConnection = Wrapped;
    };
    await tctx.addInitScript(hook);
    await learnerPage.context().grantPermissions(['camera', 'microphone']);
    await learnerPage.addInitScript(hook);
    const url = `/lessons/${lessonId}/classroom`;
    await tpage.goto(url);
    await learnerPage.goto(`/learn/lessons/${lessonId}`);
    await Promise.all([learnerPage.waitForURL(`**${url}`), learnerPage.getByRole('link', { name: 'Join the classroom' }).click()]);
    await tpage.click('#cr-join');
    await learnerPage.click('#cr-join');
    await tpage.waitForSelector('#cr-live:not([hidden])', { timeout: 15000 });
    await learnerPage.waitForSelector('#cr-live:not([hidden])', { timeout: 15000 });
    const connected = async (p) => p.evaluate(() => (window.__pcs || []).some((pc) => pc.connectionState === 'connected'));
    await until(async () => (await connected(tpage)) && (await connected(learnerPage)), { timeout: 30000, what: 'WebRTC connected in both browsers' });
    const attendees = sql('select a.role, a.user_id from classroom_attendance a join classrooms c on c.id = a.classroom_id where c.tutor_session_id = ?', [lessonId]);
    assert.ok(attendees.some((a) => a.role === 'tutor') && attendees.some((a) => a.role !== 'tutor'), 'both people on the attendance record');
    tpage.on('dialog', (d) => d.accept());
    await Promise.all([tpage.waitForURL(/\/classroom\/after$/, { timeout: 15000 }), tpage.click('#cr-end')]);
    await learnerPage.waitForSelector('#cr-notice:not([hidden])', { timeout: 15000 });
    // The tutor's summary for the learner (comms tells the learner, D-1832).
    await tpage.fill('textarea[name=summary]', 'We worked through functions and their graphs. Practise exercise 4.2.');
    await Promise.all([tpage.waitForLoadState('load'), tpage.click('button[name=publish][value="1"]')]);
    world('update', { table: 'tutor_sessions', where: { id: lessonId }, set: { starts_at: dbTime(new Date(now - 65 * 60000)) } });
    await tpage.reload();
    await Promise.all([tpage.waitForLoadState('load'), tpage.getByRole('button', { name: 'Mark the lesson completed' }).click()]);
    const s = one('select status from tutor_sessions where id = ?', [lessonId]);
    assert.equal(s.status, 'completed');
    const room = one('select status, summary_published_at, ended_at from classrooms where tutor_session_id = ?', [lessonId]);
    assert.ok(room.ended_at && room.summary_published_at, 'room ended, summary published');
    if (tutorContext) await tpage.close(); else await tctx.close();
    return `classroom: both joined (classroom_attendance ${attendees.length} rows), RTCPeerConnection "connected" in both Chromes (fake camera and microphone), tutor published a summary and ended the room (classrooms.ended_at); tutor marked it completed after the start -> tutor_sessions.status completed`;
}

/**
 * The tutor sets practice in /teach/materials: a question bank with one number question, an assessment with that
 * question, assigned to the learner. Returns the ids and the topic name.
 */
export async function setPracticeFor({ tutor, learnerId, topic = 'Linear equations', prompt = 'Solve 2x = 8. What is x?', answer = '4' }) {
    const tctx = await newContext({ width: 1440, height: 1000 });
    await signIn(tctx, tutor);
    const page = await tctx.newPage();
    const title = `After our lesson ${Date.now().toString(36)}`;
    await page.goto('/teach/materials/assessments');
    await page.fill('form[action$="/teach/materials/banks"] input[name=title]', `${topic} practice`);
    await page.fill('form[action$="/teach/materials/banks"] input[name=topic]', topic);
    await Promise.all([page.waitForURL(/\/teach\/materials\/banks\/\d+/), page.getByRole('button', { name: 'Create bank' }).click()]);
    const bankId = Number(page.url().match(/banks\/(\d+)/)[1]);
    await page.selectOption('select[name=type]', 'numeric');
    await page.fill('textarea[name=prompt]', prompt);
    await page.fill('input[name=numeric_value]', answer);
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add question' }).click()]);
    const question = one('select id from learning_questions where bank_id = ? order by id desc limit 1', [bankId]);
    assert.ok(question, 'question stored in the bank');
    await page.goto('/teach/materials/assessments');
    await page.fill('form[action$="/teach/materials/assessments"] input[name=title]', title);
    await page.selectOption('form[action$="/teach/materials/assessments"] select[name=kind]', 'tutor_assessment');
    await Promise.all([page.waitForURL(/\/teach\/materials\/assessments\/\d+/), page.getByRole('button', { name: 'Create assessment' }).click()]);
    const assessmentId = Number(page.url().match(/assessments\/(\d+)/)[1]);
    await page.check(`input[name="question_ids[]"][value="${question.id}"]`);
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add ticked questions' }).click()]);
    await page.selectOption('select[name=learner_id]', String(learnerId));
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Assign' }).click()]);
    assert.ok(one('select id from learning_assignments where assessment_id = ? and learner_id = ?', [assessmentId, learnerId]), 'assignment stored');
    await tctx.close();
    return { bankId, questionId: question.id, assessmentId, title, topic };
}

/** Uploads a PDF to the study library through the "Share material" panel (licence, ownership, rights). Returns the row. */
export async function uploadToLibrary(page, { ownerId, title, description, language = 'en', type = 'summary', level = 'grade-11' }) {
    await page.goto('/learning/library');
    await page.getByText('Share material').first().click();
    const f = page.locator('form[action$="/learning/library"][method=post], form[method=POST][action$="/learning/library"]').last();
    await f.locator('input[name=title]').fill(title);
    await f.locator('textarea[name=description]').fill(description);
    await f.locator('select[name=type]').selectOption(type);
    await f.locator('select[name=language]').selectOption(language);
    await f.locator('select[name=subject_id]').selectOption(String(subjectId('mathematics')));
    await f.locator('select[name=level_id]').selectOption(String(levelId(level)));
    await f.locator('select[name=ownership]').selectOption('own_work');
    await f.locator('select[name=licence]').selectOption('cc_by');
    await f.locator('input[name=file]').setInputFiles(fixtureFile('pdf'));
    await f.locator('#r-rights').check();
    await Promise.all([page.waitForLoadState('load'), f.getByRole('button', { name: 'Upload' }).click()]);
    return until(() => one('select * from learning_resources where owner_id = ? and title = ?', [ownerId, title]), { what: 'library resource' });
}

/** A content moderator reviews a pending library resource in /ops/moderation ("Reviewed, accurate"). */
export async function approveResource(resourceId) {
    const mod = makePerson('operator', 'ContentMod', { roles: ['content_moderator'] });
    const mctx = await newContext({ user: mod, width: 1440 });
    const mp = await mctx.newPage();
    await mp.goto('/ops/moderation?tab=resources');
    await Promise.all([mp.waitForLoadState('load'), mp.locator(`form[action*="/resources/${resourceId}/"] button[value=approved]`).first().click()]);
    await mctx.close();
    return one('select status, quality_tier from learning_resources where id = ?', [resourceId]);
}

/**
 * Off-platform screening recorded as the safeguarding screens do it: one safeguarding officer records both required
 * register checks with evidence for the tutor in /ops/safeguarding/screening, a second officer clears them (four eyes).
 */
export async function screenTutor(tutorUserId) {
    const today = new Date().toISOString().slice(0, 10);
    const a = makePerson('operator', 'OfficerA', { roles: ['safeguarding_officer'] });
    const b = makePerson('operator', 'OfficerB', { roles: ['safeguarding_officer'] });
    const actx = await newContext({ user: a, width: 1440 });
    const ap = await actx.newPage();
    for (const kind of ['child_protection_register', 'sex_offenders_register']) {
        await ap.goto('/ops/safeguarding/screening');
        const form = ap.locator(`form:has(#kind-${tutorUserId})`);
        if (!(await form.locator(`#kind-${tutorUserId}`).isVisible())) await ap.locator(`details:has(#kind-${tutorUserId}) > summary`).click();
        await form.locator(`#kind-${tutorUserId}`).selectOption(kind);
        await form.locator(`#checked-${tutorUserId}`).fill(today);
        await form.locator(`#ref-${tutorUserId}`).fill(`E2E-${kind.slice(0, 3).toUpperCase()}-${tutorUserId}`);
        await form.locator(`#evidence-${tutorUserId}`).setInputFiles(fixtureFile('pdf'));
        await Promise.all([ap.waitForLoadState('load'), form.locator('button[type=submit]').click()]);
    }
    await actx.close();
    const pending = sql("select id from safety_screening_checks where user_id = ? and status = 'pending_review'", [tutorUserId]);
    assert.equal(pending.length, 2, 'two checks recorded, waiting for a second officer');
    const bctx = await newContext({ user: b, width: 1440 });
    const bp = await bctx.newPage();
    for (const c of pending) {
        await bp.goto('/ops/safeguarding/screening');
        await Promise.all([bp.waitForLoadState('load'), bp.locator(`form[action$="/screening/checks/${c.id}/review"] button[value=cleared]`).click()]);
    }
    await bctx.close();
    return sql('select kind, status from safety_screening_checks where user_id = ?', [tutorUserId]);
}
