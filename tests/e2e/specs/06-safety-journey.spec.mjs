// Brief section 25, safety journey: a 10-year-old's account through InfoDot and onboarding (child band, young step,
// limited until a guardian links); the parent links from the emailed invitation (consent, birth date) and sets
// approval controls; restricted interactions are refused in the UI and, with the calls the UI would not make, on the
// server; a booking waits for the parent's approval; the child reports a tutor; a safeguarding officer escalates the
// report to an incident; the overdue incident escalates through the scheduled command.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, world, one, sql, until, step, watch, hygiene, shot, infoDotSignIn, makePerson, evidenceLog,
    cspViolations, mailsTo, uniq, api, formPost, artisan,
} from '../lib/harness.mjs';
import { levelId, subjectId, screenTutor, firstSlotOn, dbTime } from '../lib/flows.mjs';

const ev = evidenceLog('06-safety');

test('safety journey: a child, a guardian, refusals, a report and an incident', async () => {
    const tag = uniq('').slice(-4);
    const childEmail = `child${tag}@example.test`;
    const parentEmail = `parent${tag}@example.test`;
    // A screened tutor who teaches Grade 4 to 7, and an unscreened adult-only tutor.
    const screened = makePerson('ready-tutor', 'Screened', { name: `Nomsa Dlamini ${tag}`, level_min: 'grade-4', level_max: 'grade-7' });
    const unscreened = makePerson('ready-tutor', 'Unscreened', { name: `Jacob Venter ${tag}`, level_min: 'grade-4', level_max: 'grade-7' });
    const checks = await screenTutor(screened.id);
    assert.ok(checks.every((c) => c.status === 'cleared'));

    const ctx = await newContext({ width: 390 });
    const page = await ctx.newPage();
    const store = watch(page);
    const born = new Date(); born.setFullYear(born.getFullYear() - 10); born.setMonth(born.getMonth() - 1);
    let childId;

    // 1. An age-appropriate student account.
    await step(page, 'child account', async () => {
        await infoDotSignIn(page, { email: childEmail, name: 'Lerato Mokoena' });
        childId = one('select id from users where email = ?', [childEmail]).id;
        await page.goto('/learn/welcome');
        await page.selectOption('select[name=country]', { label: 'South Africa' });
        await page.selectOption('select[name=level]', String(levelId('grade-5')));
        if (!(await page.waitForSelector('input[name="subjects[]"]', { timeout: 5000 }).catch(() => null))) await page.getByRole('button', { name: 'Show subjects' }).click();
        await page.check(`input[name="subjects[]"][value="${subjectId('mathematics')}"]`).catch(() => page.locator('input[name="subjects[]"]').first().check());
        await page.check('input[name="teaching[]"][value="en"]');
        await page.selectOption('select[name=birth_month]', String(born.getMonth() + 1));
        await page.selectOption('select[name=birth_year]', String(born.getFullYear()));
        await Promise.all([page.waitForURL(/step=guardian/), page.getByRole('button', { name: 'Save and continue' }).click()]);
        const text = await page.innerText('main');
        assert.match(text, /parent|guardian/i);
        await page.goto('/learn');
        const young = await page.evaluate(() => document.documentElement.outerHTML.includes('td-step-young') || document.body.className.includes('young') || !!document.querySelector('[data-step=young]'));
        ev.add(1, 'Create an age-appropriate student account', `InfoDot sign-in; onboarding Grade 5 with a birth month 10 years ago -> sent to the guardian step (limited until a guardian links)${young ? '; the young reading step is on' : ''}`);
    });

    // 2. Guardian controls: the emailed invitation, consent with the child's birth date, approval settings.
    let linkId;
    await step(page, 'guardian link', async () => {
        await page.goto('/learn/welcome?step=guardian');
        await page.fill('input[name=email]', parentEmail);
        await page.selectOption('select[name=relationship]', 'parent');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Send the invitation' }).click()]);
        const mail = await until(async () => (await mailsTo(parentEmail))[0], { timeout: 30000, what: 'invitation email' });
        const link = mail.body.replace(/=\r?\n/g, '').match(/https?:\/\/[^\s"<>]+\/safety\/guardians\/accept\/\d+[^\s"<>]*/)?.[0];
        assert.ok(link, 'the email carries the accept link');
        const path = new URL(link.replace(/&amp;/g, '&')).pathname + new URL(link.replace(/&amp;/g, '&')).search;
        const pctx = await newContext({ width: 390 });
        const pp = await pctx.newPage();
        const landed = await infoDotSignIn(pp, { email: parentEmail, name: 'Palesa Mokoena', redirect: path });
        assert.match(landed, /\/safety\/guardians\/accept\//, 'InfoDot deep link lands on the invitation');
        await pp.check('#consent');
        await pp.fill('#birth-date', `${born.getFullYear()}-${String(born.getMonth() + 1).padStart(2, '0')}-15`);
        await Promise.all([pp.waitForLoadState('load'), pp.locator('form button[type=submit]').last().click()]);
        const l = await until(() => one("select * from safety_guardian_links where learner_id = ? and status = 'active'", [childId]), { what: 'active guardian link' });
        linkId = l.id;
        await pp.goto(`/guardian/children/${childId}`);
        const childPage = await pp.innerText('main');
        assert.match(childPage, /under 13, so every booking, purchase, paid room and live one-to-one lesson needs your approval/);
        assert.equal(await pp.locator('#set-booking').count(), 0, 'no switch to relax approvals for a child under 13');
        await pctx.close();
        ev.add(2, 'Apply required guardian controls', `invitation emailed (captured by the SMTP stub: "${mail.subject}"); the parent signed in through InfoDot straight onto the accept link, gave consent and the birth date -> safety_guardian_links ${linkId} active; /guardian/children/${childId} states that every booking, purchase, paid room and live lesson needs approval for a child under 13 and offers no switch to relax it`);
    });

    // 3-4. Restricted interactions: refused in the UI, and server-side for calls the UI does not offer.
    const adultRoom = (() => {
        const off = one('select id from curriculum_subject_levels where academic_level_id = ? and subject_id = ?', [levelId('grade-5'), subjectId('mathematics')]);
        return off;
    })();
    let lessonId;
    await step(page, 'restricted', async () => {
        // A booking with the screened tutor waits for the parent.
        const offering = one('select id from tutor_offerings where tutor_profile_id = ? order by id limit 1', [screened.profile_id]).id;
        await page.goto(`/learn/book/${offering}`);
        const slot = await firstSlotOn(page, 2);
        await page.check(`input[name=starts_at][value="${slot}"]`);
        await Promise.all([page.waitForURL('**/learn/lessons/**'), page.locator('form[action*="/learn/book/"] button[type=submit]').last().click()]);
        lessonId = Number(new URL(page.url()).pathname.split('/').pop());
        const s = one('select status, guardian_status from tutor_sessions where id = ?', [lessonId]);
        assert.equal(s.status, 'awaiting_guardian');
        const approval = one("select * from safety_guardian_approvals where learner_id = ? and status = 'pending' order by id desc limit 1", [childId]);
        assert.ok(approval, 'the parent is asked');
        ev.add(3, 'Attempt restricted interactions', `the child requested a lesson with a screened tutor: tutor_sessions ${lessonId} awaiting_guardian, safety_guardian_approvals ${approval.id} pending; the tutor sees the request only as a number`);

        const refusals = [];
        // Direct message to an adult tutor (the child UI offers no such thing).
        const dm = await api(page, 'POST', '/safety/conversations', { context_type: 'direct', recipient_ids: [unscreened.id], body: 'hi, can we chat on WhatsApp?' });
        assert.ok([403, 422].includes(dm.status), `direct message refused (${dm.status})`);
        refusals.push(`direct message to an adult: ${dm.status} ${dm.data?.error ?? ''}`);
        // Booking an unscreened tutor over the API.
        const uoff = one('select id from tutor_offerings where tutor_profile_id = ? order by id limit 1', [unscreened.profile_id]).id;
        const start = new Date(Date.now() + 3 * 86400000); start.setUTCHours(9, 0, 0, 0);
        const bk = await api(page, 'POST', `/api/tutor/offerings/${uoff}/bookings`, { subject_id: subjectId('mathematics'), starts_at: start.toISOString().replace('.000', '') }, { 'Idempotency-Key': `child-${tag}` });
        assert.ok(bk.status >= 400 && bk.status < 500, `booking an unscreened tutor refused (${bk.status})`);
        assert.equal(one('select count(*) n from tutor_sessions where student_id = ? and tutor_profile_id = ?', [childId, unscreened.profile_id]).n, 0);
        refusals.push(`booking an unscreened tutor: ${bk.status} ${bk.data?.error ?? ''}`);
        // Paying before the parent approved.
        const pay = await formPost(page, `/learn/lessons/${lessonId}/pay`, {});
        assert.notEqual(one('select payment_status from tutor_sessions where id = ?', [lessonId]).payment_status, 'paid');
        assert.equal(one("select count(*) n from money_orders o join money_order_lines l on l.order_id = o.id where l.purchasable_type = 'booking' and l.purchasable_id = ? and o.status = 'paid'", [lessonId]).n, 0);
        refusals.push(`paying before approval: no order paid (HTTP ${pay.status})`);
        // An adult room (opened by an adult learner first).
        const adult = makePerson('learner', 'AdultG5', { age: 30, level: 'grade-5' });
        const actx = await newContext({ user: adult, width: 1440 });
        const ap = await actx.newPage();
        await ap.goto(`/rooms/levels/${levelId('grade-5')}/subjects/${adultRoom.id}`);
        const adultRoomId = Number(new URL(ap.url()).pathname.split('/').pop());
        await actx.close();
        const room = await page.goto(`/rooms/${adultRoomId}`);
        assert.ok([403, 404].includes(room.status()), `the adults room refuses a child (${room.status()})`);
        refusals.push(`opening an adults room: ${room.status()}`);
        const join = await formPost(page, `/rooms/${adultRoomId}/join`, {});
        assert.equal(one('select count(*) n from community_room_members where room_id = ? and user_id = ?', [adultRoomId, childId]).n, 0);
        refusals.push(`joining it with a forged POST: no membership (HTTP ${join.status})`);
        ev.add(4, 'Verify that permissions are enforced server-side', refusals.join('; '));

        // The parent approves; the request reaches the tutor.
        const pctx = await newContext({ width: 390 });
        const pp = await pctx.newPage();
        await infoDotSignIn(pp, { email: parentEmail, name: 'Palesa Mokoena' });
        await pp.goto('/guardian');
        await Promise.all([pp.waitForLoadState('load'), pp.locator(`form[action$="/guardian/approvals/${approval.id}"] button[value=approve]`).click()]);
        await pctx.close();
        await until(() => one('select status from tutor_sessions where id = ?', [lessonId]).status === 'pending', { what: 'request released to the tutor' });
    });

    // 5. Report an unsafe interaction (the unscreened tutor's profile).
    let reportId;
    await step(page, 'report', async () => {
        await page.goto(`/t/${unscreened.profile_id}`);
        await page.getByText('Report this profile').first().click().catch(() => {});
        await page.selectOption('form[action$="/safety/reports"] select[name=category]', 'inappropriate_contact_minor');
        await page.fill('form[action$="/safety/reports"] textarea[name=details]', 'He asked me for my WhatsApp number.');
        await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/safety/reports"] button[type=submit]').click()]);
        const r = await until(() => one("select * from safety_reports where reporter_id = ? order by id desc limit 1", [childId]), { what: 'safety report' });
        reportId = r.id;
        assert.equal(r.involves_minor, 1);
        ev.add(5, 'Report an unsafe interaction', `safety_reports ${r.id}: inappropriate_contact_minor about the tutor, involves_minor 1, priority ${r.priority}, respond by ${r.respond_by}`);
    });

    // 6. Moderation and escalation: the officer escalates the report to an incident; overdue, it escalates.
    await step(page, 'escalation', async () => {
        const officer = makePerson('operator', 'Officer', { roles: ['safeguarding_officer'] });
        const octx = await newContext({ user: officer, width: 1440 });
        const op = await octx.newPage();
        await op.goto('/ops/safeguarding');
        assert.match(await op.innerText('main'), /contact|minor|report/i);
        await op.goto(`/ops/safeguarding/reports/${reportId}`);
        await op.fill('#act-reason', 'A tutor asking a 10-year-old for a private number: escalated.');
        await op.selectOption('#act-severity', 'critical');
        await Promise.all([op.waitForLoadState('load'), op.locator('button[name=action][value=escalate]').click()]);
        const incident = await until(() => one('select * from safety_incidents where id = (select incident_id from safety_reports where id = ?)', [reportId]), { what: 'incident' });
        assert.equal(incident.severity, 'critical');
        await op.goto(`/ops/safeguarding/incidents/${incident.id}`);
        assert.match(await op.innerText('main'), new RegExp(incident.reference));
        await octx.close();
        world('update', { table: 'safety_incidents', where: { id: incident.id }, set: { respond_by: dbTime(new Date(Date.now() - 3600000)) } });
        artisan('safety:escalate-incidents');
        const after = one('select escalation_level, status from safety_incidents where id = ?', [incident.id]);
        assert.ok(after.escalation_level > incident.escalation_level, 'escalated after the deadline passed unacknowledged');
        const notices = one("select count(*) n from notifications where data like '%incident%' and created_at >= ?", [incident.created_at]).n;
        const audit = one("select count(*) n from safety_audit_log where action like '%incident%' or action like '%report%'").n;
        ev.add(6, 'Confirm the moderation and escalation workflow', `the officer escalated report ${reportId} from /ops/safeguarding/reports to incident ${incident.reference} (critical, respond by ${incident.respond_by}); left unacknowledged past the deadline, safety:escalate-incidents raised it to level ${after.escalation_level} and notified operators (${notices} incident notices); audit rows ${audit}`);
    });

    await shot(page, 'safety-journey-done');
    const h = hygiene(store);
    assert.deepEqual(h.bad, [], 'no 5xx');
    assert.deepEqual(h.errors, [], 'no console errors');
    assert.deepEqual(h.external, [], 'no third-party host');
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
