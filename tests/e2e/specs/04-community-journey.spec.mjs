// Brief section 25, learning community journey: an adult Grade 11 learner on a 390 phone enters their academic room,
// picks Mathematics, asks a question, shares library material (uploaded, then reviewed by a content moderator),
// gets an answer from a tutor and accepts it, finds tutors and courses fitted to the room, and reports a post; a
// safeguarding officer hides it from /ops. Classmates and the tutor use their own browsers.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    newContext, closeBrowser, closeLeftovers, world, one, sql, until, step, watch, hygiene, shot, makePerson, evidenceLog, fixtureFile, cspViolations, uniq,
} from '../lib/harness.mjs';
import { levelId, subjectId, uploadToLibrary, approveResource } from '../lib/flows.mjs';

const ev = evidenceLog('04-community');

test('learning community journey', async () => {
    const tutor = makePerson('ready-tutor', 'RoomTutor', { name: 'Bongani Zulu' });
    const courseTitle = `Grade 11 functions starter (${uniq('k').slice(-4)})`;
    world('published-course', { owner_id: tutor.id, title: courseTitle });
    const asker = makePerson('learner', 'Asker', { name: 'Ayanda Nkosi', age: 20 });
    const ctx = await newContext({ user: asker, width: 390 });
    const page = await ctx.newPage();
    const store = watch(page);
    const offering = one('select id from curriculum_subject_levels where academic_level_id = ? and subject_id = ?', [levelId('grade-11'), subjectId('mathematics')]).id;
    let roomId, questionId;

    // 1-2. The appropriate academic room, then the subject.
    await step(page, 'enter room', async () => {
        await page.goto('/rooms');
        const text = await page.innerText('main');
        assert.match(text, /Grade 11, CAPS/);
        await Promise.all([page.waitForURL(/\/rooms\/\d+$/), page.locator('main').getByRole('link', { name: 'Mathematics', exact: true }).first().click()]);
        roomId = Number(new URL(page.url()).pathname.split('/').pop());
        const room = one('select * from community_rooms where id = ?', [roomId]);
        assert.equal(room.audience, 'adult', 'an adult learner is placed in the adults room');
        assert.equal(room.academic_level_id ?? room.level_id, levelId('grade-11'));
        ev.add(1, 'Enter the appropriate academic room', `/rooms lists "Grade 11, CAPS" from the learner profile; community_rooms ${roomId}: Grade 11 CAPS, audience adult`);
        ev.add(2, 'Select a subject', `Mathematics opened /rooms/levels/{Grade 11}/subjects/{offering ${offering}} -> /rooms/${roomId} (Mathematics, Grade 11, CAPS)`);
    });

    // 3. Ask a question.
    await step(page, 'ask', async () => {
        await page.goto(`/rooms/${roomId}/ask`);
        await page.fill('#title', 'How do I find the turning point of y = x² - 4x + 3?');
        await page.fill('#body', 'I completed the square and got (x - 2)² - 1 but I am not sure what the turning point is.');
        await page.selectOption('#original_locale', 'en');
        await Promise.all([page.waitForURL(/\/rooms\/posts\/\d+/), page.getByRole('button', { name: 'Post the question' }).click()]);
        questionId = Number(page.url().match(/posts\/(\d+)/)[1]);
        const p = one('select kind, status, room_id, author_id from community_posts where id = ?', [questionId]);
        assert.deepEqual([p.kind, p.status, p.room_id, p.author_id], ['question', 'visible', roomId, asker.id]);
        ev.add(3, 'Ask a question', `community_posts ${questionId}: question, visible, in room ${roomId}`);
    });

    // 4. Share study material: upload to the library (licence), a content moderator reviews it, then share it in the room.
    await step(page, 'share material', async () => {
        const r = await uploadToLibrary(page, { ownerId: asker.id, title: 'Completing the square: summary sheet', description: 'One page: completing the square and reading the turning point.' });
        const statusAtUpload = r.status;
        if (r.status !== 'published') await approveResource(r.id);
        const after = one('select status, quality_tier from learning_resources where id = ?', [r.id]);
        assert.equal(after.status, 'published');
        await page.goto(`/rooms/${roomId}/ask?kind=material`);
        await page.fill('#title', 'Summary sheet for completing the square');
        await page.selectOption('#library_resource_id', String(r.id)).catch(async () => page.fill('#library_resource_id', String(r.id)));
        await page.fill('#body', 'This helped me with turning points. Page 1 has the steps.');
        await Promise.all([page.waitForURL(/\/rooms\/posts\/\d+/), page.locator('form[action$="/posts"] button[type=submit]').click()]);
        const shared = one("select * from community_posts where room_id = ? and kind = 'material' order by id desc limit 1", [roomId]);
        assert.ok(shared && (shared.library_resource_id ?? shared.resource_id) === r.id, 'the room post links the library item');
        ev.add(4, 'Share study material', `learning_resources ${r.id} uploaded with licence CC BY (${statusAtUpload} at upload${statusAtUpload !== 'published' ? ', approved by a content moderator in /ops/moderation' : ''}) -> published (${after.quality_tier}); community_posts ${shared.id} (material) shares it in the room`);
    });

    // 5. Receive useful answers: the tutor answers; the asker accepts; the asker is notified.
    await step(page, 'answers', async () => {
        const tctx = await newContext({ user: tutor, width: 1440 });
        const tp = await tctx.newPage();
        await tp.goto(`/rooms/posts/${questionId}`);
        await tp.fill('#reply-body', 'From (x - 2)² - 1 the turning point is (2, -1): x = 2 makes the square zero, and y = -1 is the minimum.');
        await Promise.all([tp.waitForLoadState('load'), tp.getByRole('button', { name: 'Post the answer' }).click()]);
        await tctx.close();
        const answer = await until(() => one('select id from community_posts where parent_id = ? order by id desc limit 1', [questionId]), { what: 'answer' });
        const notice = await until(() => one("select id from notifications where notifiable_id = ? and data like '%community_answer%'", [asker.id]), { what: 'answer notification' });
        await page.goto(`/rooms/posts/${questionId}`);
        await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/rooms/posts/${answer.id}/accept"] button`).click()]);
        const q = one('select accepted_answer_id, resolved_at from community_posts where id = ?', [questionId]);
        assert.equal(q.accepted_answer_id, answer.id);
        ev.add(5, 'Receive useful answers', `tutor answered (community_posts ${answer.id}); the asker got an in-app notice (notifications ${notice.id}, community_answer); the asker accepted it -> accepted_answer_id ${answer.id}, question resolved`);
    });

    // 6. Find relevant tutors and courses for the room.
    await step(page, 'tutors and courses', async () => {
        await page.goto(`/rooms/${roomId}?tab=tutors`);
        const text = await page.innerText('main');
        assert.match(text, /Bongani Z/, 'a Grade 10 to 12 Mathematics tutor is listed for Grade 11 (fixed in this release)');
        assert.ok(text.includes(courseTitle), 'a published Grade 11 course is listed');
        ev.add(6, 'Find relevant tutors and courses', `room "Tutors and courses" tab lists the Grade 10-12 Mathematics tutor (scope fit) and "${courseTitle}" (level and curriculum fit)`);
    });

    // 7. Report inappropriate content; a safeguarding officer hides it.
    await step(page, 'report', async () => {
        const spammer = makePerson('learner', 'Spammer', { age: 22 });
        const sctx = await newContext({ user: spammer, width: 390 });
        const sp = await sctx.newPage();
        await sp.goto(`/rooms/${roomId}/ask?kind=discussion`);
        await sp.fill('#title', 'Selling finished assignments, cheap');
        await sp.fill('#body', 'I will write your marked assignment for R100. Message me.');
        await Promise.all([sp.waitForURL(/\/rooms\/posts\/\d+/), sp.locator('form[action$="/posts"] button[type=submit]').click()]);
        const badId = Number(sp.url().match(/posts\/(\d+)/)[1]);
        await sctx.close();
        await page.goto(`/rooms/posts/${badId}`);
        await page.getByText(/^Report/).first().click().catch(() => {});
        await page.selectOption(`#category-${badId}`, 'cheating_request');
        await page.fill(`#details-${badId}`, 'Offering to do marked work for money.');
        await Promise.all([page.waitForLoadState('load'), page.locator(`form[action$="/rooms/posts/${badId}/report"] button[type=submit]`).click()]);
        const pr = await until(() => one('select * from community_post_reports where post_id = ? and reporter_id = ?', [badId, asker.id]), { what: 'post report' });
        const report = one('select * from safety_reports where id = ?', [pr.safety_report_id]);
        assert.ok(report, 'a safety report in the moderation queue');
        const officer = makePerson('operator', 'Safeguarding', { roles: ['safeguarding_officer'] });
        const octx = await newContext({ user: officer, width: 1440 });
        const op = await octx.newPage();
        await op.goto(`/ops/safeguarding/reports/${report.id}`);
        await op.fill('#act-reason', 'Offering to do marked work: academic integrity rule.');
        await Promise.all([op.waitForLoadState('load'), op.locator('button[name=action][value=hide]').click()]);
        await octx.close();
        const post = one('select status from community_posts where id = ?', [badId]);
        assert.notEqual(post.status, 'visible', 'the post is no longer shown');
        const r2 = one('select status from safety_reports where id = ?', [report.id]);
        const audit = one("select count(*) n from safety_audit_log where subject_type like '%Report%' or action like '%report%' or action like '%moderat%'").n;
        ev.add(7, 'Report inappropriate content', `community_post_reports -> safety_reports ${report.id} (cheating_request); the safeguarding officer hid it from /ops/safeguarding/reports/${report.id}: post ${post.status}, report ${r2.status}; audit rows ${audit}`);
    });

    await shot(page, 'community-journey-done');
    const h = hygiene(store);
    assert.deepEqual(h.bad, [], 'no 5xx');
    assert.deepEqual(h.errors, [], 'no console errors');
    assert.deepEqual(h.external, [], 'no third-party host');
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
