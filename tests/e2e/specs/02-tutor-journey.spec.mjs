// Brief section 25, tutor journey, in Chrome at 1440 (the learner on a 390 phone, a verification reviewer and a
// finance operator in their own browsers): InfoDot sign-in, the /teach onboarding steps (profile, photo, scope,
// languages, qualification with private evidence, availability, an offering with the commission preview), review in
// /ops, a lesson request, accept, the lesson, earnings pending then available, bank details, a payout batch paid.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, world, one, sql, until, step, watch, hygiene, shot, interceptCheckout, infoDotSignIn,
    signIn, makePerson, artisan, evidenceLog, uniq, fixtureFile, cspViolations,
} from '../lib/harness.mjs';
import { levelId, subjectId, payOnLessonPage, attendLesson, firstSlotOn, dbTime, chooseSlot, bookSubmit } from '../lib/flows.mjs';

const ev = evidenceLog('02-tutor');

test('tutor journey: register to payout', async () => {
    const email = `${uniq('lerato')}@example.test`;
    const ctx = await newContext({ width: 1440, height: 1000 });
    const page = await ctx.newPage();
    const store = watch(page);
    let profileId;

    // 1. Register (InfoDot), then "Become a tutor".
    await step(page, 'register', async () => {
        await infoDotSignIn(page, { email, name: 'Lerato Khumalo' });
        await page.goto('/teach/profile');
        assert.match(await page.innerText('h1'), /Become a tutor/);
        ev.add(1, 'Register', 'InfoDot sign-in provisions the account; /teach/profile opens "Become a tutor" before any profile exists');
    });
    const userId = one('select id from users where email = ?', [email]).id;

    // 2. Create a tutor profile (about you, photo).
    await step(page, 'profile', async () => {
        await page.fill('input[name=headline]', 'CAPS Mathematics, Grades 10 to 12, in isiZulu and English');
        await page.fill('textarea[name=introduction]', 'I help FET learners understand functions, algebra and calculus, step by step.');
        await page.fill('textarea[name=teaching_approach]', 'Short explanations, then worked examples, then practice.');
        await page.selectOption('select[name=timezone]', 'Africa/Johannesburg');
        await Promise.all([page.waitForURL('**step=photo**'), page.click('form[action$="/teach/profile/start"] button[type=submit]')]);
        const p = one('select * from tutor_profiles where user_id = ?', [userId]);
        assert.equal(p.status, 'draft');
        assert.equal(p.hourly_rate_minor, null, 'offerings own prices: no profile-wide rate (D-2204)');
        profileId = p.id;
        await page.setInputFiles('input[name=photo]', fixtureFile('png'));
        await Promise.all([page.waitForURL('**step=scope**', { timeout: 20000 }), page.getByRole('button', { name: 'Upload photo' }).click()]);
        assert.ok(one('select photo_path from tutor_profiles where id = ?', [profileId]).photo_path, 'photo stored (re-encoded, private disk)');
        ev.add(2, 'Create a tutor profile', `tutor_profiles ${profileId}: draft, headline, introduction, approach, no profile-wide rate (offerings own prices); photo uploaded and stored`);
    });

    // 3. Subjects and academic levels.
    await step(page, 'scope', async () => {
        // The subject picker is a searchable list grouped by area (D-2205): type, then choose.
        await page.fill('#scope-0-subject', 'maths');
        await page.click(`#scope-0-subject-listbox [role=option][data-value="${subjectId('mathematics')}"]`);
        await page.selectOption('select[name="scopes[0][level_min_id]"]', String(levelId('grade-10')));
        await page.selectOption('select[name="scopes[0][level_max_id]"]', String(levelId('grade-12')));
        await Promise.all([page.waitForURL('**step=languages**'), page.getByRole('button', { name: 'Save and continue' }).click()]);
        const scope = one('select * from tutor_teaching_scopes where tutor_profile_id = ?', [profileId]);
        assert.equal(scope.level_min_id, levelId('grade-10'));
        assert.equal(scope.level_max_id, levelId('grade-12'));
        ev.add(3, 'Select subjects and academic levels', 'tutor_teaching_scopes: Mathematics, CAPS Grade 10 to Grade 12 (validated against the academic structure)');
    });

    // 4. Languages.
    await step(page, 'languages', async () => {
        await page.selectOption('select[name="languages[0][code]"]', 'zu');
        await page.selectOption('select[name="languages[0][proficiency]"]', 'native');
        await page.selectOption('select[name="languages[1][code]"]', 'en');
        await page.selectOption('select[name="languages[1][proficiency]"]', 'fluent');
        await Promise.all([page.waitForURL('**step=qualifications**'), page.getByRole('button', { name: 'Save and continue' }).click()]);
        const langs = sql("select l.code, ul.proficiency, ul.verified_at from user_languages ul join languages l on l.id = ul.language_id where ul.user_id = ? and ul.role = 'can_teach_in' order by l.code", [userId]);
        assert.deepEqual(langs.map((l) => l.code), ['en', 'zu']);
        assert.ok(langs.every((l) => l.verified_at === null), 'self-declared, not verified');
        ev.add(4, 'Specify languages', `user_languages can_teach_in: ${langs.map((l) => `${l.code} (${l.proficiency}, self-declared)`).join(', ')}`);
    });

    // 5. Verification documents.
    let qualificationId;
    await step(page, 'qualification', async () => {
        await page.selectOption('select[name=kind]', 'degree');
        await page.fill('input[name=title]', 'BSc Mathematics');
        await page.fill('input[name=institution]', 'University of KwaZulu-Natal');
        await page.fill('input[name=year_awarded]', '2019');
        await page.setInputFiles('input[name=file][id=new-evidence]', fixtureFile('pdf'));
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Record qualification' }).click()]);
        const q = await until(() => one('select * from tutor_qualifications where tutor_profile_id = ?', [profileId]), { what: 'qualification row' });
        qualificationId = q.id;
        const evidence = one('select * from tutor_qualification_evidence where tutor_qualification_id = ?', [q.id]);
        assert.ok(evidence, 'evidence stored');
        await page.goto('/teach/profile?step=qualifications');
        await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/qualifications/${q.id}/submit"] button`).click()]);
        const after = one('select status from tutor_qualifications where id = ?', [q.id]);
        assert.match(after.status, /submitted|pending|review/);
        // The evidence file is private: another signed-in person gets nothing.
        const other = makePerson('user', 'Nosy');
        const octx = await newContext({ user: other });
        const r = await octx.request.get(`/api/tutor/me/evidence/${evidence.id}`);
        assert.ok([403, 404].includes(r.status()), `evidence refused to another user (${r.status()})`);
        await octx.close();
        ev.add(5, 'Submit verification documents', `tutor_qualifications ${q.id}: degree with PDF evidence (tutor_qualification_evidence ${evidence.id}, private disk), sent for review -> ${after.status}; the evidence URL answers ${r.status()} to another user`);
        // Teaching under-18s: adults only for now (screening is recorded by operators off-platform).
        await page.goto('/teach/profile?step=safeguarding');
        await page.check('input[name=teaches_minors][value="0"]');
        await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/teach/profile/safeguarding"] button[type=submit]').click()]);
    });

    // 6. Availability (calendar).
    await step(page, 'availability', async () => {
        await page.goto('/teach/calendar');
        for (const day of [1, 2, 3, 4, 5, 6, 7]) {
            await page.selectOption('select[name=weekday]', String(day));
            await page.fill('input[name=start_time]', '06:00');
            await page.fill('input[name=end_time]', '22:00');
            await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add hours' }).click()]);
        }
        const n = one('select count(*) n from tutor_availability_windows where tutor_profile_id = ?', [profileId]).n;
        assert.equal(n, 7);
        ev.add(6, 'Configure availability', '7 tutor_availability_windows (every day 06:00-22:00 SAST) added on /teach/calendar');
    });

    // 7. Pricing: an offering with the live commission preview.
    let offeringId;
    await step(page, 'pricing', async () => {
        await page.goto('/teach/offerings/new?type=one_to_one');
        await page.fill('input[name=title]', 'Grade 11 Maths, one-to-one');
        // Levels are grouped checkboxes that work on touch (D-2206).
        await page.check(`input[name="level_ids[]"][value="${levelId('grade-11')}"]`);
        await page.selectOption('select[name=duration_minutes]', '60');
        await page.selectOption('select[name=format]', 'online');
        await page.fill('input[name=price]', '300');
        await page.locator('input[name=price]').blur();
        await page.waitForFunction(() => /255[.,]00/.test(document.querySelector('main').innerText), null, { timeout: 10000 });
        await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/new')), page.getByRole('button', { name: 'Save offering' }).click()]);
        const o = one('select * from tutor_offerings where tutor_profile_id = ? order by id desc limit 1', [profileId]);
        assert.equal(o.price_minor, 30000);
        offeringId = o.id;
        ev.add(7, 'Set pricing', `tutor_offerings ${o.id}: one-to-one, 60 min, price_minor 30000; the form showed "you keep R255.00" (15% commission) from the server before saving`);
    });

    // Send for review; a verification reviewer approves the profile and verifies the qualification in /ops.
    await step(page, 'review in ops', async () => {
        await page.goto('/teach/profile?step=send');
        await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/teach/profile/submit"] button[type=submit]').click()]);
        assert.equal(one('select status from tutor_profiles where id = ?', [profileId]).status, 'pending');
        const reviewer = makePerson('operator', 'Reviewer', { roles: ['verification_reviewer'] });
        const rctx = await newContext({ user: reviewer, width: 1440 });
        const rp = await rctx.newPage();
        await rp.goto(`/ops/verification/tutors/${profileId}`);
        await rp.fill(`#q-note-${qualificationId}`, 'Degree certificate checked against the PDF.');
        await Promise.all([rp.waitForLoadState('load'), rp.locator(`form[action*="/qualifications/${qualificationId}"] button[value=verify], form:has(#q-note-${qualificationId}) button[value=verify]`).first().click()]);
        await rp.goto(`/ops/verification/tutors/${profileId}`);
        await Promise.all([rp.waitForLoadState('load'), rp.locator('form[action*="/standing"] button[value=approve]').first().click()]);
        await rctx.close();
        assert.equal(one('select status from tutor_profiles where id = ?', [profileId]).status, 'approved');
        const q = one('select status from tutor_qualifications where id = ?', [qualificationId]);
        ev.add('5b', 'Verification reviewed', `reviewer (verification_reviewer role) verified qualification -> ${q.status}; approved the profile -> approved; safety_audit_log rows: ${one("select count(*) n from safety_audit_log where action like '%verif%' or action like '%approve%'").n}`);
    });

    // 8-9. A learner requests a lesson; the tutor sees it on Today and accepts it in Requests.
    const learner = makePerson('learner', 'Anele', { age: 20, languages: ['zu'] });
    const lctx = await newContext({ user: learner, width: 390 });
    await interceptCheckout(lctx);
    const lpage = await lctx.newPage();
    const lstore = watch(lpage);
    let lessonId;
    await step(page, 'request and accept', async () => {
        await lpage.goto(`/learn/book/${offeringId}`);
        const slot = await firstSlotOn(lpage, 2);
        await chooseSlot(lpage, slot);
        await Promise.all([lpage.waitForURL('**/learn/lessons/**'), bookSubmit(lpage).click()]);
        lessonId = Number(new URL(lpage.url()).pathname.split('/').pop());
        await page.goto('/teach');
        assert.match(await page.innerText('main'), /1 request/i, 'Today leads with the waiting request');
        ev.add(8, 'Receive a tutoring request', `tutor_sessions ${lessonId} requested by the learner; /teach (Today) shows "1 request waiting"`);
        await page.goto('/teach/bookings?view=requests');
        await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/teach/bookings/${lessonId}/accept"] button[type=submit]`).click()]);
        assert.equal(one('select status from tutor_sessions where id = ?', [lessonId]).status, 'awaiting_payment');
        ev.add(9, 'Accept the booking', 'Accept on /teach/bookings -> awaiting_payment (payment hold)');
    });

    // 10. Deliver the lesson (the learner pays first).
    await step(page, 'deliver', async () => {
        await payOnLessonPage(lpage, lessonId);
        const res = await attendLesson({ lessonId, tutorContext: ctx, learnerPage: lpage });
        ev.add(10, 'Deliver the lesson', res);
    });

    // 11. Track earnings: pending after the lesson, available after the hold (settlement sweep, as cron runs it).
    await step(page, 'earnings', async () => {
        const earning = await until(() => one("select e.* from money_earnings e join money_order_lines l on l.id = e.order_line_id where l.purchasable_type = 'booking' and l.purchasable_id = ?", [lessonId]), { what: 'earning row' });
        assert.equal(earning.gross_minor, 30000);
        assert.equal(earning.commission_minor, 4500);
        assert.equal(earning.net_minor, 25500);
        await page.goto('/teach/earnings');
        assert.match(await page.innerText('main'), /R255\.00/);
        const pendingStatus = earning.status;
        world('update', { table: 'money_earnings', where: { id: earning.id }, set: { release_after: dbTime(new Date(Date.now() - 2 * 86400000)) } });
        artisan('money:settle');
        const released = one('select status, available_at from money_earnings where id = ?', [earning.id]);
        assert.equal(released.status, 'available');
        ev.add(11, 'Track earnings', `money_earnings ${earning.id}: gross 30000, commission 4500, net 25500, ${pendingStatus} after the lesson; after the 24 h hold (moved back) money:settle released it -> available; /teach/earnings shows R255.00`);
        world('update', { table: 'money_earnings', where: { id: earning.id }, set: { available_at: dbTime(new Date(Date.now() - 2 * 86400000)) } });
    });

    // 12. Payout: bank details, finance drafts the batch and marks the payout paid in /ops; the tutor sees it.
    await step(page, 'payout', async () => {
        await page.goto('/teach/payouts');
        await page.fill('input[name=holder_name]', 'Lerato Khumalo');
        await page.fill('input[name=bank_name]', 'Test Bank');
        await page.fill('input[name=bank_code]', '250655');
        await page.fill('input[name=account_number]', '62000012345');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Save bank details' }).click()]);
        const account = one('select * from money_payout_accounts where user_id = ?', [userId]);
        assert.ok(account, 'payout account stored');
        assert.ok(!JSON.stringify(account).includes('62000012345'), 'the account number is not stored in clear');
        const finance = makePerson('operator', 'Finance', { roles: ['finance'] });
        const fctx = await newContext({ user: finance, width: 1440 });
        const fp = await fctx.newPage();
        await fp.goto('/ops/payouts');
        await Promise.all([fp.waitForLoadState('load'), fp.getByRole('button', { name: 'Draft a batch now' }).click()]);
        const payout = await until(() => one('select * from money_payouts where payee_id = ? order by id desc limit 1', [userId]), { what: 'payout drafted' });
        assert.equal(payout.amount_minor, 25500);
        await fp.goto(`/ops/payouts/batches/${payout.batch_id}`);
        await Promise.all([fp.waitForLoadState('load'), fp.locator(`form[action$="/payouts/${payout.id}/paid"] button`).click()]);
        await fctx.close();
        const paid = one('select status from money_payouts where id = ?', [payout.id]);
        assert.equal(paid.status, 'paid');
        await page.goto('/teach/payouts');
        assert.match(await page.innerText('main'), /Paid/i);
        ev.add(12, 'Receive or track payout', `bank details saved (money_payout_accounts, number not stored in clear); finance drafted the batch on /ops/payouts -> money_payouts ${payout.id} 25500; marked paid on the batch page -> paid; /teach/payouts shows it paid`);
    });

    await shot(page, 'tutor-journey-done');
    for (const [who, st] of [['tutor', store], ['learner', lstore]]) {
        const h = hygiene(st);
        assert.deepEqual(h.bad, [], `${who}: no 5xx`);
        assert.deepEqual(h.errors, [], `${who}: no console errors`);
        assert.deepEqual(h.external, [], `${who}: no third-party host`);
    }
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
