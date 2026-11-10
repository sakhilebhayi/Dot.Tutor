// Builds public/icons/td-sprite.svg from Lucide icon sources (ISC licence).
//
// Usage: node resources/js/ds/build-sprite.mjs <path to an unpacked lucide-static package>
// The sprite is committed; this script runs only when someone adds an icon to ICONS below.
// Nothing here runs at request time and nothing is loaded from a CDN.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ICONS = [
    'search', 'menu', 'x', 'chevron-down', 'chevron-right', 'chevron-left', 'arrow-right', 'arrow-left',
    'check', 'circle-check', 'circle-alert', 'triangle-alert', 'info', 'loader-circle', 'house', 'book-open',
    'library', 'graduation-cap', 'users', 'user', 'calendar', 'clock', 'message-square', 'bell', 'settings',
    'log-out', 'languages', 'shield-check', 'badge-check', 'file', 'upload', 'download', 'paperclip', 'wallet',
    'banknote', 'receipt', 'chart-no-axes-column', 'list-checks', 'layers', 'school', 'sun', 'moon', 'monitor',
    'plus', 'minus', 'pencil', 'trash-2', 'external-link', 'funnel', 'flag', 'lock', 'eye', 'inbox', 'folder',
    'video', 'star', 'circle-help', 'book-marked', 'notebook-pen', 'scale', 'life-buoy', 'clipboard-check',
    'map-pin', 'globe', 'user-round-check', 'hand-heart', 'circle-dashed', 'hourglass',
];

const lucide = process.argv[2];
if (!lucide) {
    console.error('Pass the path to an unpacked lucide-static package.');
    process.exit(1);
}

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '../../../public/icons/td-sprite.svg');

const symbols = ICONS.map((name) => {
    const svg = readFileSync(join(lucide, 'icons', `${name}.svg`), 'utf8');
    const inner = svg
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/^[\s\S]*?<svg[^>]*>/, '')
        .replace(/<\/svg>\s*$/, '')
        .replace(/\s*\n\s*/g, '')
        .trim();

    return `<symbol id="i-${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
});

mkdirSync(dirname(out), { recursive: true });
writeFileSync(
    out,
    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + '<!-- Icons from Lucide (lucide-static 1.71.0), ISC licence, https://lucide.dev. Built by resources/js/ds/build-sprite.mjs. -->'
        + symbols.join('')
        + '</svg>\n',
);
console.log(`Wrote ${ICONS.length} icons to ${out}`);
