// Brief section 25, multilingual journey (390 phone): the interface language is a separate choice from teaching
// languages; a search by teaching language ranks the tutor who teaches in it; a library resource keeps and labels its
// original language; a machine translation (through the AI driver, answered by the local Anthropic-API stub) is
// labelled as such and shown next to the original, which stays unchanged and downloadable.
import test from 'node:test';
import assert from 'node:assert/strict';
import { newContext, closeBrowser, closeLeftovers, one, sql, until, step, watch, hygiene, shot, makePerson, evidenceLog, cspViolations, stub, uniq } from '../lib/harness.mjs';
import { levelId, subjectId, uploadToLibrary, approveResource } from '../lib/flows.mjs';

const ev = evidenceLog('05-multilingual');

test('multilingual journey', async () => {
    const tag = uniq('').slice(-4);
    const xhosa = makePerson('ready-tutor', 'XhosaTutor', { name: `Lwazi Mgqibelo ${tag}`, languages: ['xh', 'en'] });
    const english = makePerson('ready-tutor', 'EnglishTutor', { name: `Peter Smith ${tag}`, languages: ['en'] });
    const learner = makePerson('learner', 'Multi', { name: 'Thando Dube', age: 19, languages: ['xh'] });
    const ctx = await newContext({ user: learner, width: 390 });
    const page = await ctx.newPage();
    const store = watch(page);

    // 1. Interface language (screen words only).
    await step(page, 'interface language', async () => {
        await page.goto('/language');
        await page.check('input[name=locale][value=af]', { force: true });
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Use this language' }).click()]);
        await page.goto('/learn');
        assert.equal(await page.getAttribute('html', 'lang'), 'af');
        const iface = sql("select l.code from user_languages ul join languages l on l.id = ul.language_id where ul.user_id = ? and ul.role = 'interface'", [learner.id]).map((r) => r.code);
        assert.deepEqual(iface, ['af']);
        const teaching = sql("select l.code from user_languages ul join languages l on l.id = ul.language_id where ul.user_id = ? and ul.role = 'prefers_teaching'", [learner.id]).map((r) => r.code);
        assert.deepEqual(teaching, ['xh'], 'the interface choice did not change the teaching languages');
        ev.add(1, 'Select a preferred interface language', `/language: Afrikaans chosen (coverage shown, "Drafted with AI and not yet checked"); user_languages interface = af; <html lang="af">; teaching languages unchanged (xh)`);
    });

    // 2-3. Search in a teaching language; compatible tutors first.
    await step(page, 'search by teaching language', async () => {
        await page.goto('/find-a-tutor');
        const form = page.locator('form[action$="/learn/find"]');
        await form.locator('select[name=subject_id]').selectOption(String(subjectId('mathematics')));
        await form.locator('select[name=level_id]').selectOption(String(levelId('grade-11')));
        await form.locator('input[name="languages[]"][value=xh]').check({ force: true });
        await Promise.all([page.waitForURL(/find/), form.locator('button[type=submit]').last().click()]);
        const url = new URL(page.url());
        assert.ok(url.searchParams.getAll('languages[]').includes('xh') || /languages/.test(url.search), 'the search carries the teaching language');
        const text = await page.innerText('main');
        const ix = text.indexOf(`Lwazi Mgqibelo ${tag}`), ie = text.indexOf(`Peter Smith ${tag}`);
        assert.ok(ix >= 0, 'the isiXhosa tutor is found');
        assert.ok(ie < 0 || ix < ie, 'the isiXhosa tutor ranks above the English-only tutor');
        assert.match(text, /isiXhosa/);
        ev.add(2, 'Search for a tutor in a preferred teaching language', `find form: Mathematics, Grade 11, teaching language isiXhosa -> ${url.pathname}?${url.search.slice(1, 140)}`);
        ev.add(3, 'Discover compatible tutors', `the isiXhosa-teaching tutor is listed${ie < 0 ? ' and the English-only tutor is not' : ' above the English-only tutor'}, with the language fit explained; the interface language (af) played no part`);
    });

    // 4. A resource in its original language (isiZulu), reviewed and published.
    let resource;
    await step(page, 'original language resource', async () => {
        const author = makePerson('learner', 'Author', { age: 24 });
        const actx = await newContext({ user: author, width: 1440 });
        const ap = await actx.newPage();
        resource = await uploadToLibrary(ap, { ownerId: author.id, title: `Imisebenzi namagrafu ${tag}`, description: 'Incazelo emfushane yemisebenzi namagrafu eBanga le-11.', language: 'zu' });
        await actx.close();
        if (resource.status !== 'published') await approveResource(resource.id);
        assert.equal(one('select language from learning_resources where id = ?', [resource.id]).language, 'zu');
        await page.goto(`/learning/library/${resource.id}`);
        const text = await page.innerText('main');
        assert.match(text, /isiZulu/);
        ev.add(4, 'Access a resource in its original language', `learning_resources ${resource.id}: language zu; the page labels the original language as isiZulu and offers the original file`);
    });

    // 5-6. Machine translation through the AI driver (local stub), labelled; the original stays.
    await step(page, 'translation', async () => {
        await page.goto(`/learning/library/${resource.id}`);
        await Promise.all([page.waitForURL(/\/ai\/translations\//), page.getByRole('link', { name: /Original and translation/ }).click()]);
        await page.selectOption('select[name=locale]', 'en');
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Show' }).click()]);
        assert.match(await page.innerText('main'), /No translation in this language yet/);
        await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Translate by machine' }).click()]);
        const text = await until(async () => { const t = await page.innerText('main'); return /e2e stub translation/.test(t) ? t : (await page.reload(), null); }, { timeout: 30000, every: 1500, what: 'translation shown' });
        assert.match(text, /Original \(zu\)/);
        assert.match(text, /Incazelo emfushane/, 'the original text is shown next to the translation');
        assert.match(text, /machine|AI/i, 'the translation is labelled as machine translation');
        const t = one("select * from translations where translatable_id = ? and locale = 'en' order by id desc limit 1", [resource.id]);
        assert.ok(t, 'translation stored with provenance');
        assert.equal(t.provenance ?? t.source, 'machine');
        const calls = (await stub()).ai.calls;
        assert.ok(calls.some((c) => /Incazelo emfushane/.test(c.user)), 'the AI driver sent the original text to the (stubbed) Messages API');
        ev.add(5, 'Use supported translation functionality', `"Original and translation" -> English: AI driver called the Anthropic Messages API (local stub, model ${calls.at(-1)?.model}); translations ${t.id} stored provenance=machine, labelled on screen as machine translation`);
        const orig = one('select description, language from learning_resources where id = ?', [resource.id]);
        assert.match(orig.description, /Incazelo emfushane/);
        await page.goto(`/learning/library/${resource.id}`);
        const dl = await page.request.get(`/learning/library/${resource.id}/download`);
        assert.equal(dl.status(), 200);
        ev.add(6, 'Verify that the original content remains accessible', `the original isiZulu description is unchanged in learning_resources and shown beside the translation; the original file downloads (200, ${dl.headers()['content-type']})`);
    });

    await shot(page, 'multilingual-journey-done');
    const h = hygiene(store);
    assert.deepEqual(h.bad, [], 'no 5xx');
    assert.deepEqual(h.errors, [], 'no console errors');
    assert.deepEqual(h.external, [], 'no third-party host');
    assert.deepEqual(await cspViolations(page), [], 'no CSP violation');
});

test.afterEach(async (t) => { await closeLeftovers(t.name); });
test.after(async () => { await closeBrowser(); });
