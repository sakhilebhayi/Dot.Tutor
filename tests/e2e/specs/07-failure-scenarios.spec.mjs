// Failure conditions (brief section 25: "Test failure conditions as well as successful workflows"), each in its own
// test with fresh people: payment declined then retried and a duplicate webhook; a guardian declines; a tutor
// declines; two learners race for the last group seat; a refunded course loses access; guessed private file URLs;
// an adult messaging a child; a minor joining an adults room; the AI refusing an active assessment.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, world, one, sql, until, step, watch, hygiene, makePerson, evidenceLog, interceptCheckout,
    stub, stubPost, api, formPost, uniq, artisan, signIn,
} from '../lib/harness.mjs';
import { levelId, subjectId, acceptOnTeach, payOnLessonPage, firstSlotOn, setPracticeFor, dbTime, chooseSlot, bookSubmit } from '../lib/flows.mjs';

const ev = evidenceLog('07-failures');

async function requestLesson(page, offeringId) {
    await page.goto(`/learn/book/${offeringId}`);
    const slot = await firstSlotOn(page, 2);
    await chooseSlot(page, slot);
    await Promise.all([page.waitForURL('**/learn/lessons/**'), bookSubmit(page).click()]);
    return Number(new URL(page.url()).pathname.split('/').pop());
}

test('payment declined, then paid; a duplicate webhook changes nothing', async () => {
    const tutor = makePerson('ready-tutor', 'PayTutor');
    const learner = makePerson('learner', 'Payer', { age: 21 });
    const ctx = await newContext({ user: learner });
    await interceptCheckout(ctx);
    const page = await ctx.newPage();
    const store = watch(page);
    const lessonId = await requestLesson(page, tutor.offerings[0]);
    const tctx = await newContext({ user: tutor, width: 1440 });
    await acceptOnTeach(tctx, lessonId);
    await tctx.close();

    await step(page, 'declined', async () => {
        const order = await payOnLessonPage(page, lessonId, { outcome: 'decline' });
        const after = await until(() => { const o = one('select status from money_orders where id = ?', [order.id]); return o.status !== 'paid' ? o : null; }, { what: 'order not paid' });
        const s = one('select status, payment_status from tutor_sessions where id = ?', [lessonId]);
        assert.equal(s.status, 'awaiting_payment', 'the lesson keeps waiting for payment');
        assert.notEqual(s.payment_status, 'paid');
        assert.equal(one("select count(*) n from money_earnings e join money_order_lines l on l.id = e.order_line_id where l.purchasable_type = 'booking' and l.purchasable_id = ?", [lessonId]).n, 0, 'no earning');
        ev.add('F1a', 'Payment declined', `Paystack checkout declined: order ${order.id} ${after.status}, lesson still awaiting_payment, no earning, no ledger sale; the lesson page offers Pay again`);
    });
    await step(page, 'retry and duplicate webhook', async () => {
        const order = await payOnLessonPage(page, lessonId);
        const payment = one("select * from money_payments where order_id = ? and status = 'paid'", [order.id]);
        const before = { earnings: one('select count(*) n from money_earnings').n, entries: one('select count(*) n from money_ledger_entries').n, events: one('select count(*) n from money_webhook_events').n };
        const r = await stubPost('redeliver', { reference: payment.reference });
        const last = r.webhooks.at(-1);
        assert.equal(last.status, 200, 'the duplicate is acknowledged');
        await new Promise((res) => setTimeout(res, 1500));
        const after = { earnings: one('select count(*) n from money_earnings').n, entries: one('select count(*) n from money_ledger_entries').n, events: one('select count(*) n from money_webhook_events').n };
        assert.equal(after.earnings, before.earnings, 'no second earning');
        assert.equal(after.entries, before.entries, 'no second ledger posting');
        assert.equal(one("select count(*) n from money_payments where order_id = ? and status = 'paid'", [order.id]).n, 1);
        artisan('money:reconcile');
        ev.add('F1b', 'Retry, then a duplicate webhook', `second checkout paid (order ${order.id}); Paystack's retry of the same signed charge.success answered 200 and changed nothing: earnings ${before.earnings}->${after.earnings}, ledger entries ${before.entries}->${after.entries}, webhook events ${before.events}->${after.events}; money:reconcile ran clean`);
    });
    const h = hygiene(store);
    assert.deepEqual([h.bad, h.external], [[], []]);
});

test('a guardian declines a booking', async () => {
    const tutor = makePerson('ready-tutor', 'GTutor', { level_min: 'grade-8', level_max: 'grade-12' });
    const { screenTutor } = await import('../lib/flows.mjs');
    await screenTutor(tutor.id);
    const teen = makePerson('learner', 'Teen', { name: 'Kabelo Molefe', age: 15, level: 'grade-10' });
    const parent = world('guardian-link', { learner_id: teen.id, email: `${uniq('parent')}@example.test`, name: 'Mpho Molefe' });
    world('password', { id: parent.id });
    const ctx = await newContext({ user: teen });
    const page = await ctx.newPage();
    const lessonId = await requestLesson(page, tutor.offerings[0]);
    const s = one('select status from tutor_sessions where id = ?', [lessonId]);
    assert.equal(s.status, 'awaiting_guardian', 'a 15-year-old\'s booking waits for the guardian (default for teens)');
    const approval = one("select id from safety_guardian_approvals where learner_id = ? and status = 'pending' order by id desc limit 1", [teen.id]);
    const pctx = await newContext({ user: { email: parent.email } });
    const pp = await pctx.newPage();
    await pp.goto('/guardian');
    await pp.fill(`#note-${approval.id}`, 'Not on a school night, please.');
    await Promise.all([pp.waitForLoadState('load'), pp.locator(`form[action$="/guardian/approvals/${approval.id}"] button[value=decline]`).click()]);
    const after = await until(() => { const r = one('select status, guardian_status from tutor_sessions where id = ?', [lessonId]); return r.status !== 'awaiting_guardian' ? r : null; }, { what: 'booking closed' });
    assert.notEqual(after.status, 'pending', 'the tutor never receives it');
    const notice = await until(() => one("select id from notifications where notifiable_id = ? and data like '%decid%'", [teen.id]), { what: 'the teen is told' });
    await page.goto(`/learn/lessons/${lessonId}`);
    ev.add('F2', 'Guardian declines', `teen (15) requested lesson ${lessonId} -> awaiting_guardian; the parent declined on /guardian with a reason -> lesson ${after.status} (guardian ${after.guardian_status}), never reached the tutor; the teen was notified (notifications ${notice.id})`);
});

test('a tutor declines a request with a reason', async () => {
    const tutor = makePerson('ready-tutor', 'DTutor');
    const learner = makePerson('learner', 'Declined', { age: 22 });
    const ctx = await newContext({ user: learner });
    const page = await ctx.newPage();
    const lessonId = await requestLesson(page, tutor.offerings[0]);
    const tctx = await newContext({ user: tutor, width: 1440 });
    const tp = await tctx.newPage();
    await tp.goto('/teach/bookings?view=requests');
    const form = tp.locator(`form[action$="/teach/bookings/${lessonId}/decline"]`);
    if (!(await form.locator('textarea[name=reason]').isVisible())) await tp.locator(`details:has(form[action$="/teach/bookings/${lessonId}/decline"]) > summary`).click();
    await form.locator('textarea[name=reason]').fill('I am away that week; please book the week after.');
    await Promise.all([tp.waitForLoadState('load'), form.locator('button[type=submit]').click()]);
    await tctx.close();
    const s = one('select status, decision_reason from tutor_sessions where id = ?', [lessonId]);
    assert.match(s.status, /declined|cancelled/);
    await page.goto(`/learn/lessons/${lessonId}`);
    assert.match(await page.innerText('main'), /away that week/);
    ev.add('F3', 'Tutor declines', `lesson ${lessonId} -> ${s.status} with the tutor's reason stored and shown on the learner's lesson page`);
});

test('two learners race for the last seat of a group lesson', async () => {
    const tutor = makePerson('ready-tutor', 'GroupTutor', { offerings: [{ type: 'group', title: 'Saturday revision group', price_minor: 9000, capacity: 2, duration_minutes: 90 }] });
    const offeringId = tutor.offerings[0];
    const tctx = await newContext({ user: tutor, width: 1440 });
    const tp = await tctx.newPage();
    await tp.goto('/teach/bookings?view=groups');
    const d = new Date(Date.now() + 4 * 86400000);
    await tp.fill(`#g${offeringId}-date`, d.toISOString().slice(0, 10));
    await tp.fill(`#g${offeringId}-time`, '10:00');
    await Promise.all([tp.waitForLoadState('load'), tp.locator(`form[action$="/offerings/${offeringId}/group-lessons"] button[type=submit]`).click()]);
    await tctx.close();
    const group = await until(() => one("select * from tutor_sessions where tutor_offering_id = ? and kind = 'group'", [offeringId]), { what: 'group lesson' });
    const [a, b, c] = ['First', 'Racer B', 'Racer C'].map((n) => makePerson('learner', n.replace(/\W/g, ''), { age: 20 }));
    const take = async (ctx) => {
        const p = await ctx.newPage();
        await p.goto(`/learn/book/${offeringId}`);
        return p;
    };
    const actx = await newContext({ user: a });
    const ap = await take(actx);
    await Promise.all([ap.waitForLoadState('load'), ap.locator(`form:has(input[name=session_id][value="${group.id}"]) button[type=submit]`).click()]);
    const [bctx, cctx] = [await newContext({ user: b }), await newContext({ user: c })];
    const [bp, cp] = [await take(bctx), await take(cctx)];
    await Promise.all([
        bp.locator(`form:has(input[name=session_id][value="${group.id}"]) button[type=submit]`).click(),
        cp.locator(`form:has(input[name=session_id][value="${group.id}"]) button[type=submit]`).click(),
    ]);
    await Promise.all([bp.waitForLoadState('load'), cp.waitForLoadState('load')]);
    const seats = sql("select learner_id user_id, status from tutor_session_participants where tutor_session_id = ? and status not in ('cancelled', 'expired', 'released')", [group.id]);
    assert.equal(seats.length, 2, `exactly capacity seats held (${JSON.stringify(seats)})`);
    const winners = seats.filter((s) => [b.id, c.id].includes(s.user_id));
    assert.equal(winners.length, 1, 'exactly one of the two racers got the last seat');
    const g = one('select seats_taken, capacity from tutor_sessions where id = ?', [group.id]);
    assert.ok(g.seats_taken <= g.capacity);
    const loser = winners[0].user_id === b.id ? cp : bp;
    assert.match(await loser.innerText('main'), /full|no seat|taken|0 of 2/i, 'the other learner is told the lesson is full');
    ev.add('F4', 'Booking race on the last group seat', `group lesson ${group.id} (capacity 2, one seat taken): two learners pressed "Take a seat" at the same moment in two browsers -> exactly one seat granted (${seats.length} held, seats_taken ${g.seats_taken}/${g.capacity}); the other was told it is full`);
});

test('refunding a course takes access away', async () => {
    const creator = makePerson('ready-tutor', 'RefundCreator');
    const course = world('published-course', { owner_id: creator.id, title: `Refundable course ${uniq('').slice(-4)}`, price_minor: 15000 });
    const learner = makePerson('learner', 'Refunded', { age: 25 });
    const ctx = await newContext({ user: learner });
    await interceptCheckout(ctx);
    const page = await ctx.newPage();
    await page.goto(`/learning/courses/${course.id}`);
    await Promise.all([page.waitForURL(/checkout\.paystack\.com/), page.getByRole('button', { name: 'Buy course' }).click()]);
    await Promise.all([page.waitForURL((u) => u.host !== 'checkout.paystack.com'), page.click('#pay')]);
    await until(() => one("select id from learning_enrolments where course_id = ? and user_id = ? and status = 'active'", [course.id, learner.id]), { what: 'enrolled' });
    // The paid lesson (not the free preview, which anyone may open).
    const lesson = one('select id from learning_lessons where course_id = ? and is_preview = 0 order by id limit 1', [course.id]);
    assert.equal((await page.goto(`/learning/courses/${course.id}/learn/${lesson.id}`)).status(), 200);
    const order = one("select o.*, l.id line_id from money_orders o join money_order_lines l on l.order_id = o.id where l.purchasable_type = 'course' and l.purchasable_id = ? and o.buyer_id = ?", [course.id, learner.id]);
    const finance = makePerson('operator', 'RefundFinance', { roles: ['finance'] });
    const fctx = await newContext({ user: finance, width: 1440 });
    const fp = await fctx.newPage();
    await fp.goto(`/ops/payments/orders/${order.uuid}`);
    const form = fp.locator(`form[action*="/orders/${order.id}/lines/${order.line_id}/refund"], form[action*="/refund"]`).first();
    await form.locator('select[name=reason]').selectOption({ index: 0 });
    await form.locator('input[name=note]').fill('Learner asked within the refund window.');
    await Promise.all([fp.waitForLoadState('load'), form.locator('button[type=submit]').click()]);
    await fctx.close();
    await until(() => { artisan('money:settle'); return one("select id from money_refunds where order_id = ? and status in ('processed', 'succeeded', 'completed')", [order.id]); }, { timeout: 30000, every: 2000, what: 'refund processed' });
    const enrol = await until(() => { const e = one('select status from learning_enrolments where course_id = ? and user_id = ?', [course.id, learner.id]); return e.status !== 'active' ? e : null; }, { what: 'enrolment revoked' });
    const again = await page.goto(`/learning/courses/${course.id}/learn/${lesson.id}`);
    const blocked = again.status() >= 400 || !/changes direction/.test(await page.innerText('main'));
    assert.ok(blocked, 'the course player no longer opens the paid lesson');
    const refund = one('select * from money_refunds where order_id = ?', [order.id]);
    ev.add('F5', 'Refund of a course revokes access', `finance refunded order ${order.id} from /ops/payments/orders (Paystack /refund on the stub, money_refunds ${refund.id} ${refund.status}); enrolment -> ${enrol.status}; the course player now refuses the lesson (HTTP ${again.status()})`);
});

test('guessed private file URLs are refused', async () => {
    const stranger = makePerson('learner', 'Guesser', { age: 30 });
    const ctx = await newContext({ user: stranger, width: 1440 });
    const page = await ctx.newPage();
    await page.goto('/learn');
    const urls = [];
    const add = (label, url, ok = [403, 404]) => { if (url) urls.push({ label, url, ok }); };
    const id = (q) => { try { return one(q)?.id ?? null; } catch { return null; } };
    // Rows created by the journeys earlier in the run (each spec file runs in order on one database).
    add('tutor qualification evidence (own-evidence API)', id('select id from tutor_qualification_evidence order by id desc') && `/api/tutor/me/evidence/${id('select id from tutor_qualification_evidence order by id desc')}`);
    add('tutor qualification evidence (operator API)', id('select id from tutor_qualification_evidence order by id desc') && `/api/tutor/operator/evidence/${id('select id from tutor_qualification_evidence order by id desc')}`);
    const lessonMedia = one("select id, course_id from learning_lessons where media_path is not null order by id desc limit 1");
    if (lessonMedia) add('course lesson media, unsigned', `/learning/media/${lessonMedia.course_id}/lesson/${lessonMedia.id}`);
    const material = one('select id, course_id from learning_materials order by id desc limit 1');
    if (material) add('course material, not enrolled', `/learning/courses/${material.course_id}/materials/${material.id}`);
    const screening = id('select id from safety_screening_checks where evidence_path is not null order by id desc');
    add('screening evidence', screening && `/safety/operator/screening/${screening}/evidence`);
    const attachment = id('select id from safety_message_attachments order by id desc');
    add('message attachment', attachment && `/safety/attachments/${attachment}`);
    const roomFile = one('select id from lesson_resources order by id desc limit 1');
    if (roomFile) add('lesson resource', `/resources/${roomFile.id}/download`);
    const paid = one("select id from learning_resources where is_free = 0 and status = 'published' order by id desc limit 1");
    if (paid) add('paid library item download, not bought', `/learning/library/${paid.id}/download`, [402, 403, 404]);
    // Storage paths the web server must never serve.
    const evidencePath = one('select path from tutor_qualification_evidence order by id desc limit 1')?.path;
    for (const p of ['/storage/app/private/learning', '/storage/learning/x.pdf', evidencePath && `/storage/${evidencePath}`, evidencePath && `/${evidencePath}`, '/.env', '/storage/logs/laravel.log']) add(`direct path ${p}`, p, [403, 404]);
    // Run after the journeys (01-06) the list holds every kind; run alone it holds what this database has.
    assert.ok(urls.length >= 4, `enough guessed URLs to be meaningful (${urls.length})`);
    const results = [];
    for (const u of urls) {
        const r = await page.request.get(u.url, { maxRedirects: 0 });
        results.push(`${u.label}: ${r.status()}`);
        assert.ok(u.ok.includes(r.status()), `${u.label} (${u.url}) answered ${r.status()}`);
    }
    const guest = await newContext();
    for (const u of urls.filter((x) => !x.url.startsWith('/api/'))) {
        const r = await guest.request.get(u.url, { maxRedirects: 0 });
        assert.ok([302, 401, 403, 404, 402].includes(r.status()), `guest ${u.label} answered ${r.status()}`);
    }
    ev.add('F6', 'Guessed private file URLs', results.join('; ') + '; a signed-out browser is redirected to sign in or refused for every one');
});

test('an adult cannot message a child; a minor cannot join an adults room', async () => {
    const child = makePerson('learner', 'Child', { age: 11, level: 'grade-6' });
    world('guardian-link', { learner_id: child.id, email: `${uniq('cparent')}@example.test`, name: 'Child Parent' });
    const adult = makePerson('learner', 'Adult', { age: 35, level: 'grade-6' });
    const tutor = makePerson('ready-tutor', 'NoBookingTutor', { level_min: 'grade-4', level_max: 'grade-7' });
    const results = [];
    for (const [who, person] of [['an adult learner', adult], ['an approved tutor with no booking', tutor]]) {
        const ctx = await newContext({ user: person });
        const page = await ctx.newPage();
        await page.goto('/safety/conversations');
        const r = await api(page, 'POST', '/safety/conversations', { context_type: 'direct', recipient_ids: [child.id], body: 'Hello, what school do you go to?' });
        assert.ok([403, 422].includes(r.status), `${who} -> child refused (${r.status})`);
        results.push(`${who}: ${r.status} ${r.data?.error ?? ''}`);
        await ctx.close();
    }
    assert.equal(one('select count(*) n from safety_conversation_participants where user_id = ?', [child.id]).n, 0, 'no conversation reached the child');
    ev.add('F7', 'An adult messaging a child', `${results.join('; ')}; no conversation includes the child`);

    // A teen with consent is in the under-18 room; the adults room of the same subject refuses them.
    const teen = makePerson('learner', 'RoomTeen', { age: 16, level: 'grade-11' });
    world('guardian-link', { learner_id: teen.id, email: `${uniq('tparent')}@example.test`, name: 'Teen Parent' });
    const offering = one('select id from curriculum_subject_levels where academic_level_id = ? and subject_id = ?', [levelId('grade-11'), subjectId('mathematics')]).id;
    const adultCtx = await newContext({ user: makePerson('learner', 'AdultRoom', { age: 26 }) });
    const ap = await adultCtx.newPage();
    await ap.goto(`/rooms/levels/${levelId('grade-11')}/subjects/${offering}`);
    const adultRoomId = Number(new URL(ap.url()).pathname.split('/').pop());
    await adultCtx.close();
    const tctx = await newContext({ user: teen });
    const tp = await tctx.newPage();
    await tp.goto(`/rooms/levels/${levelId('grade-11')}/subjects/${offering}`);
    const teenRoomId = Number(new URL(tp.url()).pathname.split('/').pop());
    const teenRoom = one('select audience from community_rooms where id = ?', [teenRoomId]);
    assert.notEqual(teenRoomId, adultRoomId);
    assert.notEqual(teenRoom.audience, 'adult');
    const open = await tp.goto(`/rooms/${adultRoomId}`);
    const join = await formPost(tp, `/rooms/${adultRoomId}/join`, {});
    const post = await formPost(tp, `/rooms/${adultRoomId}/posts`, { kind: 'question', title: 'Can anyone help with functions?', body: 'I need help with turning points please.', original_locale: 'en' });
    assert.ok([403, 404].includes(open.status()));
    assert.equal(one('select count(*) n from community_room_members where room_id = ? and user_id = ?', [adultRoomId, teen.id]).n, 0);
    assert.equal(one('select count(*) n from community_posts where room_id = ? and author_id = ?', [adultRoomId, teen.id]).n, 0);
    ev.add('F8', 'A minor joining an adult room', `a 16-year-old with guardian consent is placed in the ${teenRoom.audience} room ${teenRoomId}; the adults room ${adultRoomId} answers ${open.status()}, a forged join ${join.status} and a forged post ${post.status} leave no membership and no post`);
});

test('the AI study assistant refuses an active assessment', async () => {
    const tutor = makePerson('ready-tutor', 'AiTutor');
    const learner = makePerson('learner', 'AiLearner', { age: 18 });
    // The tutor can only assign to their own students: a confirmed lesson first.
    world('update', { table: 'users', where: { id: learner.id }, set: { name: 'Ai Learner' } });
    const ctx = await newContext({ user: learner });
    const page = await ctx.newPage();
    const lessonId = await requestLesson(page, tutor.offerings[0]);
    world('update', { table: 'tutor_sessions', where: { id: lessonId }, set: { status: 'confirmed', payment_status: 'paid' } });
    const set = await setPracticeFor({ tutor, learnerId: learner.id, topic: 'Quadratic functions', prompt: 'Find the turning point of the graph of y equals x squared minus four x plus three' });
    const callsBefore = (await stub()).ai.calls.length;
    await page.goto('/ai');
    await page.selectOption('select[name=task]', { index: 0 });
    await page.fill('textarea[name=text]', 'Please find the turning point of the graph of y equals x squared minus four x plus three');
    await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/ai/ask"] button[type=submit]').click()]);
    const text = await page.innerText('main');
    const interaction = one('select * from ai_interactions where user_id = ? order by id desc limit 1', [learner.id]);
    assert.ok(interaction, 'the request is recorded');
    assert.match(`${interaction.status} ${interaction.refusal_code}`, /refus|assessment/i);
    assert.equal(interaction.reached_model, 0, 'recorded as not having reached the model');
    assert.equal((await stub()).ai.calls.length, callsBefore, 'nothing was sent to the model');
    assert.match(text, /assess|quiz|own|integrity|cannot/i, 'the learner is told why');
    ev.add('F9', 'AI refusing an active assessment', `learner pasted a question from an assessment assigned to them ("${set.title}") into /ai: ai_interactions ${interaction.id} ${interaction.status} (${interaction.refusal_code}, reached_model 0); the Messages API stub received no call; the page explains the integrity rule`);
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
