// R-47 a11y (DEVELOPMENT ONLY; docs/R47_A11Y.md): reduced motion. With prefers-reduced-motion: reduce, no page runs a
// CSS animation or a transition longer than 0.01 ms; the same pages with motion allowed are counted to show the check
// bites. The live classroom (two people joined) is included.
//   node scripts/a11y/motion.mjs
import { BASE, browser, person, php, sleep, writeJson } from './lib.mjs';

const ids = JSON.parse(php('scripts/a11y/ids.php'));
const PAGES = [[null, '/'], [null, '/login'], ['demo.grade3@example.test', '/learn'], ['demo.matric@example.test', '/learn'], ['demo.matric@example.test', `/find-a-tutor?subject_id=${ids.maths}`],
    ['demo.adult@example.test', `/rooms/${ids.adultRoom}`], ['demo.workspace.tutor@example.test', '/teach'], ['demo.workspace.tutor@example.test', '/teach/insights'], ['demo.ops.admin@example.test', '/ops'],
    ['demo.ops.guardian@example.test', '/guardian'], ['demo.classroom.learner@example.test', `/lessons/${ids.classroomLesson}/classroom`]];
const MEASURE = `(() => {
    const running = document.getAnimations().filter((a) => { const t = a.effect?.getComputedTiming?.(); return t && (t.duration === Infinity || t.duration > 1) && a.playState === 'running'; });
    let transitions = 0; const which = [];
    for (const e of document.querySelectorAll('body *')) { const d = getComputedStyle(e).transitionDuration.split(',').map(parseFloat); if (d.some((x) => x > 0.001)) { transitions++; if (which.length < 3) which.push(e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0]); } }
    const smooth = getComputedStyle(document.documentElement).scrollBehavior === 'smooth';
    return { animations: running.length, names: running.slice(0, 4).map((a) => a.animationName || a.constructor.name), transitions, which, smooth };
})()`;
const results = [];
const b = await browser();
try {
    php('artisan', ['db:seed', '--class=DemoClassroomSeeder', '--force']);
    for (const reduce of [false, true]) {
        for (const [who, path] of PAGES) {
            const p = await person(b, who, { width: 390, reducedMotion: reduce ? 'reduce' : 'no-preference' });
            await p.page.goto(BASE + path); await sleep(1200);
            // Hover and focus something so hover/focus transitions are in play.
            await p.page.keyboard.press('Tab'); await p.page.keyboard.press('Tab');
            const m = await p.page.evaluate(MEASURE);
            const ok = !reduce || (m.animations === 0 && m.transitions === 0 && !m.smooth);
            results.push({ path, reduce, ...m, ok });
            console.log(`${ok ? 'PASS' : 'FAIL'}  ${reduce ? 'reduce ' : 'motion '} ${path.padEnd(34)} animations ${m.animations} ${m.names.join(',')} transitions ${m.transitions} ${m.which.join(',')} smooth-scroll ${m.smooth}`);
            await p.ctx.close();
        }
    }
} finally { await b.close(); }
writeJson('motion.json', results);
process.exit(results.some((r) => !r.ok) ? 1 : 0);
