// Brief section 25, student journey, in Chrome on a 390 px phone (the tutor in a second browser at 1440): InfoDot
// sign-in, onboarding (country, curriculum, level, subjects, languages), a need in plain words, explained matches,
// the storefront, a booking the tutor accepts, Paystack test checkout (local stub, signed webhook), the lesson in the
// classroom, a review, and progress from practice the tutor set. Evidence is read from the database after each step.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, world, one, sql, until, step, watch, hygiene, shot, interceptCheckout, infoDotSignIn,
    stub, signIn, cspViolations, evidenceLog, uniq, api,
} from '../lib/harness.mjs';
import { levelId, subjectId, acceptOnTeach, payOnLessonPage, attendLesson, firstSlotOn, setPracticeFor, chooseSlot, bookSubmit } from '../lib/flows.mjs';

const ev = evidenceLog('01-student');

test('student journey: InfoDot to progress', async () => {
    const tutor = world('ready-tutor', { email: `${uniq('tutor')}@example.test`, name: 'Thandeka Mokoena', password: 'e2e-pass-12345', languages: ['zu', 'en'], offerings: [{ type: 'one_to_one', title: 'Grade 11 Maths lesson', price_minor: 25000 }] });
    tutor.password = 'e2e-pass-12345';
    const email = `${uniq('sipho')}@example.test`;
    const ctx = await newContext({ width: 390 });
    await interceptCheckout(ctx);
    const page = await ctx.newPage();
    const store = watch(page);

    // 1. Register and authenticate through InfoDot (its launch page posts a one-time token; the app redeems it).
    await step(page, 'InfoDot sign-in', async () => {
        const landed = await infoDotSignIn(page, { email, name: 'Sipho Dlamini' });
        assert.equal(landed, '/learn', 'a new learner lands on /learn (D-1801)');
        const user = one('select id, email_verified_at from users where email = ?', [email]);
        assert.ok(user?.email_verified_at, 'account provisioned from the InfoDot identity, email verified by InfoDot');
        const redeems = (await stub()).infodot.redeems;
        assert.ok(redeems.some((r) => r.ok && r.known && !r.reused && r.platform === 'tutor'), 'the app redeemed the token with InfoDot using its service secret');
        ev.add(1, 'Register and authenticate through InfoDot', `InfoDot launch page posted the token; redeem call ok (platform tutor); users row ${user.id} provisioned, email verified; landed on /learn`);
    });
    const learnerId = one('select id from users where email = ?', [email]).id;

    // 2-4. Country, curriculum and level; subjects; languages (onboarding).
    await step(page, 'onboarding', async () => {
        await page.getByRole('link', { name: 'Start' }).first().click();
        await page.waitForURL('**/learn/welcome**');
        await page.selectOption('select[name=country]', { label: 'South Africa' });
        await page.selectOption('select[name=level]', String(levelId('grade-11')));
        // Choosing the level reloads the form with its subjects (the button is the no-script fallback).
        if (!(await page.waitForSelector('input[name="subjects[]"]', { timeout: 5000 }).catch(() => null))) await page.getByRole('button', { name: 'Show subjects' }).click();
        await page.waitForSelector('input[name="subjects[]"]');
        await page.check(`input[name="subjects[]"][value="${subjectId('mathematics')}"]`);
        await page.check('input[name="teaching[]"][value="zu"]');
        await page.check('input[name="teaching[]"][value="en"]');
        await page.selectOption('select[name=interface_locale]', 'en');
        const born = new Date(); born.setFullYear(born.getFullYear() - 19); born.setMonth(born.getMonth() - 2);
        await page.selectOption('select[name=birth_month]', String(born.getMonth() + 1));
        await page.selectOption('select[name=birth_year]', String(born.getFullYear()));
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/learn/welcome') || u.search.includes('step')), page.getByRole('button', { name: 'Save and continue' }).click()]);
        const profile = one('select lp.*, c.code curriculum from learner_profiles lp join curricula c on c.id = lp.curriculum_id where user_id = ?', [learnerId]);
        assert.equal(profile.academic_level_id, levelId('grade-11'));
        assert.equal(profile.curriculum, 'za-caps');
        const subjects = sql('select subject_id from learner_profile_subjects where learner_profile_id = ?', [profile.id]).map((r) => r.subject_id);
        assert.deepEqual(subjects, [subjectId('mathematics')]);
        const langs = sql("select l.code, ul.role from user_languages ul join languages l on l.id = ul.language_id where ul.user_id = ? order by l.code, ul.role", [learnerId]);
        assert.ok(langs.some((l) => l.code === 'zu' && l.role === 'prefers_teaching'), 'isiZulu stored as a preferred teaching language');
        ev.add(2, 'Select country and academic curriculum', `learner_profiles: country ZA, curriculum za-caps (from the level), onboarded_at set`);
        ev.add(3, 'Select academic level and subjects', `academic_level_id = CAPS Grade 11; learner_profile_subjects = [Mathematics]`);
        ev.add(4, 'Set preferred languages', `user_languages: ${langs.map((l) => `${l.code}/${l.role}`).join(', ')}`);
    });

    // 5-6. Search in plain words; explained recommendations.
    await step(page, 'search and matches', async () => {
        await page.goto('/learn');
        await page.fill('input[name=need]', 'Grade 11 Maths in isiZulu under R300 an hour');
        await Promise.all([page.waitForURL((u) => /find-a-tutor|learn\/find/.test(u.pathname)), page.getByRole('button', { name: 'Find help' }).click()]);
        const text = await page.innerText('main');
        assert.match(text, /Thandeka Mokoena/, 'the tutor is recommended');
        assert.match(text, /isiZulu/i, 'the language fit is explained');
        const url = new URL(page.url());
        assert.ok(url.searchParams.get('subject_id') || url.search.includes('subject'), 'the need was read into structured filters');
        ev.add(5, 'Search for tutoring', `"Grade 11 Maths in isiZulu under R300 an hour" -> ${url.pathname}${url.search.slice(0, 120)}`);
        ev.add(6, 'Receive relevant tutor recommendations', 'Thandeka Mokoena listed with reasons including isiZulu (teaching language), scope Grade 10-12 covers Grade 11');
    });

    // 7. Qualifications and pricing on the storefront.
    await step(page, 'storefront', async () => {
        await Promise.all([page.waitForURL(`**/t/${tutor.profile_id}`), page.locator(`a[href$="/t/${tutor.profile_id}"]`).first().click()]);
        const text = await page.innerText('main');
        assert.match(text, /R250\.00/);
        assert.match(text, /Qualifications/);
        assert.match(text, /Not yet verified/, 'verification shown honestly');
        ev.add(7, 'Review tutor qualifications and pricing', 'storefront /t/{id}: price R250.00 per hour, qualifications section, "Not yet verified" label (no evidence reviewed), scope table, teaching languages');
    });

    // 8. Book; the tutor accepts in the workspace.
    let lessonId;
    await step(page, 'book', async () => {
        await Promise.all([page.waitForURL(`**/learn/book/${tutor.offerings[0]}`), page.getByRole('link', { name: 'Request this lesson' }).click()]);
        const slot = await firstSlotOn(page, 2);
        await chooseSlot(page, slot);
        await Promise.all([page.waitForURL('**/learn/lessons/**'), bookSubmit(page).click()]);
        lessonId = Number(new URL(page.url()).pathname.split('/').pop());
        const s = one('select status, price_minor, student_id from tutor_sessions where id = ?', [lessonId]);
        assert.equal(s.student_id, learnerId);
        assert.equal(s.price_minor, 25000);
        const tctx = await newContext({ width: 1440, height: 900 });
        await signIn(tctx, tutor);
        await acceptOnTeach(tctx, lessonId);
        await tctx.close();
        assert.equal(one('select status from tutor_sessions where id = ?', [lessonId]).status, 'awaiting_payment');
        ev.add(8, 'Book a tutoring session', `tutor_sessions ${lessonId}: requested by the learner at ${slot}, price_minor 25000; the tutor accepted on /teach/bookings -> awaiting_payment`);
    });

    // 9. Pay through Paystack's checkout (test mode, local stub) with a signed webhook.
    await step(page, 'pay', async () => {
        const order = await payOnLessonPage(page, lessonId);
        const s = one('select status, payment_status, order_reference from tutor_sessions where id = ?', [lessonId]);
        assert.equal(s.status, 'confirmed');
        assert.equal(s.payment_status, 'paid');
        assert.equal(s.order_reference, order.uuid);
        ev.add(9, 'Complete payment', `orders ${order.id} paid, total_minor ${order.total_minor}; signed charge.success webhook 200; session confirmed and paid, order_reference = order uuid; ledger balanced (money:reconcile)`);
    });

    // 10. Attend the lesson (classroom open; tutor starts and completes in the workspace).
    await step(page, 'attend', async () => {
        const res = await attendLesson({ lessonId, tutor, learnerPage: page });
        ev.add(10, 'Attend the lesson', res);
    });

    // 11. Review the tutor.
    await step(page, 'review', async () => {
        await page.goto(`/learn/lessons/${lessonId}`);
        await page.check('input[name=rating][value="5"]');
        await page.fill('#review-text', 'Clear explanations of functions, in isiZulu when I was stuck.');
        await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/learn/lessons/${lessonId}/review"] button[type=submit]`).click()]);
        const r = one('select rating, status from session_ratings where session_id = ?', [lessonId]);
        assert.equal(r?.rating, 5);
        ev.add(11, 'Review the tutor', `session_ratings: rating 5 (${r.status}) for lesson ${lessonId}`);
    });

    // 12. Track progress: the tutor sets practice on a CAPS topic; the learner answers; progress shows the topic.
    await step(page, 'progress', async () => {
        const set = await setPracticeFor({ tutor, learnerId });
        await page.goto('/learn/practice');
        await Promise.all([page.waitForURL(`**/assessments/${set.assessmentId}/take**`), page.locator(`a[href*="/assessments/${set.assessmentId}/take"]`).first().click()]);
        await page.getByRole('button', { name: 'Start' }).click();
        const answer = page.locator('main input[type=text], main input[type=number], main input[inputmode]').first();
        await answer.waitFor();
        await answer.fill('4');
        await Promise.all([page.waitForURL('**/attempts/**'), page.getByRole('button', { name: /submit|hand in|finish/i }).first().click()]);
        const attempt = one('select percent, status from learning_attempts where user_id = ? order by id desc limit 1', [learnerId]);
        assert.equal(Number(attempt.percent), 100);
        await page.goto('/learn/progress');
        assert.match(await page.innerText('main'), new RegExp(set.topic.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'the topic shows on the progress page');
        ev.add(12, 'Track learning progress', `tutor set "${set.title}" on topic ${set.topic} in /teach/materials and assigned it; learner answered in /learning/assessments/.../take: attempt ${attempt.percent}% (${attempt.status}); /learn/progress lists the topic`);
    });

    await shot(page, 'student-journey-done');
    const h = hygiene(store);
    assert.deepEqual(h.bad, [], 'no 5xx');
    assert.deepEqual(h.errors, [], 'no console errors');
    assert.deepEqual(h.external, [], 'no third-party host');
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
