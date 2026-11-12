// R-47 a11y (DEVELOPMENT ONLY; docs/R47_A11Y.md section 4): the young-learner step's copy against a Grade 1-3 reading
// level. For each page a Grade 3 learner sees: the visible text of the main region split into sentences, the
// Flesch-Kincaid grade of the page, and every sentence longer than 12 words or with a word of four or more syllables
// (a heuristic syllable count; names and numbers excluded). A guide for the human review, not a verdict.
//   node scripts/a11y/young-copy.mjs
import { BASE, browser, person, php, sleep, writeJson } from './lib.mjs';

const ids = JSON.parse(php('scripts/a11y/ids.php'));
const PAGES = ['/learn', '/find-a-tutor', '/learn/courses', '/learn/progress', '/learn/practice', '/learn/account', '/rooms', '/comms/inbox', '/safety/me', '/ai', `/t/${ids.thandekaProfile}`];
const syllables = (w) => { w = w.toLowerCase().replace(/[^a-z]/g, ''); if (w.length <= 3) return 1; w = w.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '').replace(/^y/, ''); const m = w.match(/[aeiouy]{1,2}/g); return Math.max(1, m ? m.length : 1); };
const results = [];
const b = await browser();
try {
    const p = await person(b, 'demo.grade3@example.test', { width: 390 });
    for (const path of PAGES) {
        await p.page.goto(BASE + path); await sleep(500);
        const step = await p.page.evaluate(() => document.documentElement.dataset.tdStep || 'standard');
        const text = await p.page.evaluate(() => { const m = document.querySelector('main') || document.body; return m.innerText; });
        const sentences = text.split(/\n+|(?<=[.!?])\s+/).map((s) => s.trim()).filter((s) => s.split(/\s+/).length >= 3 && /[a-z]/i.test(s));
        let words = 0; let syl = 0; const hard = [];
        for (const s of sentences) {
            const ws = s.split(/\s+/).filter((w) => /[a-z]/i.test(w) && !/^[A-Z][a-z]+$/.test(w) || /^[a-z]/.test(w));
            words += ws.length; const ss = ws.map(syllables); syl += ss.reduce((a, c) => a + c, 0);
            const long = ws.filter((w, i) => ss[i] >= 4 && /^[a-z]/.test(w));
            if (ws.length > 12 || long.length) hard.push(`${ws.length}w${long.length ? ' [' + long.join(', ') + ']' : ''}: ${s.slice(0, 140)}`);
        }
        const fk = sentences.length && words ? 0.39 * (words / sentences.length) + 11.8 * (syl / words) - 15.59 : 0;
        results.push({ path, step, sentences: sentences.length, words, fk_grade: Math.round(fk * 10) / 10, hard });
        console.log(`${path.padEnd(16)} step=${step} sentences ${sentences.length} words ${words} FK grade ${fk.toFixed(1)} hard ${hard.length}`);
        for (const h of hard.slice(0, 8)) console.log('     ' + h);
    }
    await p.ctx.close();
} finally { await b.close(); }
writeJson('young-copy.json', results);
