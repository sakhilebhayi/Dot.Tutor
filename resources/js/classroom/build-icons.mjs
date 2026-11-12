// Builds public/classroom/icons.svg, the classroom's own icon sprite (Lucide, ISC licence), in the same way as the
// design system's resources/js/ds/build-sprite.mjs. Committed output; runs only when someone adds an icon:
//   node resources/js/classroom/build-icons.mjs <unpacked lucide-static>
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ICONS = [
    'mic', 'mic-off', 'video', 'video-off', 'monitor-up', 'monitor-x', 'sliders-horizontal', 'phone-off', 'pen-line',
    'eraser', 'minus', 'square', 'circle', 'type', 'plus', 'image-down', 'send', 'flag', 'user-x', 'volume-x', 'wifi',
    'wifi-off', 'external-link', 'users', 'message-square', 'notebook-pen', 'file', 'upload', 'download', 'presentation',
    'trash-2', 'chevron-left', 'chevron-right', 'chevron-down', 'x', 'check', 'triangle-alert', 'info', 'refresh-cw', 'lock',
];

const lucide = process.argv[2];
if (!lucide) {
    console.error('Pass the path to an unpacked lucide-static package.');
    process.exit(1);
}
const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '../../../public/classroom/icons.svg');
const symbols = ICONS.map((name) => {
    const inner = readFileSync(join(lucide, 'icons', `${name}.svg`), 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '').replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').replace(/\s*\n\s*/g, '').trim();
    return `<symbol id="i-${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
});
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
    + '<!-- Icons from Lucide (lucide-static 1.71.0), ISC licence, https://lucide.dev. Built by resources/js/classroom/build-icons.mjs. -->'
    + symbols.join('') + '</svg>\n');
console.log(`Wrote ${ICONS.length} icons to ${out}`);
