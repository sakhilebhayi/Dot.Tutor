// Prints the evidence the last run wrote (tests/e2e/out/evidence-*.json) as Markdown tables, for docs/R47_QA.md.
// Usage: node tests/e2e/report.mjs > /tmp/evidence.md
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const out = join(dirname(fileURLToPath(import.meta.url)), 'out');
const cell = (s) => String(s).replace(/\|/g, '\\|').replace(/\n/g, ' ');
for (const f of readdirSync(out).filter((n) => /^evidence-.*\.json$/.test(n)).sort()) {
    console.log(`\n#### ${f.replace(/^evidence-|\.json$/g, '')}\n\n| # | Step | Result | Evidence |\n|---|---|---|---|`);
    for (const r of JSON.parse(readFileSync(join(out, f), 'utf8'))) console.log(`| ${cell(r.step)} | ${cell(r.title)} | ${r.result} | ${cell(r.evidence)} |`);
}
if (existsSync(join(out, 'summary.txt'))) console.log('\n```\n' + readFileSync(join(out, 'summary.txt'), 'utf8') + '```');
