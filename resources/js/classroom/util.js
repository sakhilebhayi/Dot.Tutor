// Small helpers for the classroom modules (adapted from Dot.Engage's meeting util.js).

let sprite = '/classroom/icons.svg';
export function setSprite(url) { if (url) sprite = url; }

export const $ = (id) => document.getElementById(id);

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function randomId(bytes = 9) {
    const a = new Uint8Array(bytes);
    (self.crypto || window.crypto).getRandomValues(a);
    return Array.from(a, (b) => b.toString(36).padStart(2, '0')).join('').slice(0, bytes * 2 - 2);
}

export function safeParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
}

export function initials(name) {
    const parts = String(name || '?').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const first = Array.from(parts[0])[0] || '?';
    const last = parts.length > 1 ? (Array.from(parts[parts.length - 1])[0] || '') : '';
    return (first + last).toUpperCase();
}

export function fmtClock(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const rr = String(s % 60).padStart(2, '0');
    return h > 0 ? `${h}:${mm}:${rr}` : `${mm}:${rr}`;
}

/** Times are shown in the viewer's own timezone, named explicitly (CI runs in UTC). */
export function fmtTime(iso, timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone) {
    if (!iso) return '';
    try { return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', timeZone }).format(new Date(iso)); } catch (e) { return ''; }
}

/** An icon from the classroom sprite. Decorative: the control around it carries the name. */
export function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'td-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${sprite}#i-${name}`);
    svg.append(use);
    return svg;
}

export function swapIcon(button, name) {
    const use = button?.querySelector('use');
    if (use) use.setAttribute('href', `${sprite}#i-${name}`);
}

/** Create an element: h('button', {class: 'x', type: 'button'}, 'text', childNode). Text is always text, never HTML. */
export function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === false || value === null || value === undefined) continue;
        if (key === 'class') node.className = value;
        else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
        else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child.nodeType ? child : document.createTextNode(String(child)));
    }
    return node;
}

export function isTyping(target) {
    if (!target || !target.tagName) return false;
    const tag = target.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable === true;
}

export const QUALITY_WORD = { good: 'Good', fair: 'Fair', poor: 'Poor' };

export function worstQuality(list) {
    const order = { good: 0, fair: 1, poor: 2 };
    let worst = null;
    for (const q of list) {
        if (!q) continue;
        if (worst === null || order[q] > order[worst]) worst = q;
    }
    return worst;
}
