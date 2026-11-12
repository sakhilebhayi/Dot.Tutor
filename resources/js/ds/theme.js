// Theme: system (default), light or dark. The choice lives in localStorage on this device only; the inline
// script in layouts/partials/head applies it before first paint so the page never flashes the wrong theme.
const KEY = 'td-theme';
const MODES = ['system', 'light', 'dark'];
const LABELS = { system: 'Theme: follows your device', light: 'Theme: light', dark: 'Theme: dark' };

export function readTheme() {
    try {
        const stored = window.localStorage.getItem(KEY);
        return MODES.includes(stored) ? stored : 'system';
    } catch {
        return 'system';
    }
}

export function applyTheme(mode) {
    const root = document.documentElement;
    if (mode === 'light' || mode === 'dark') {
        root.setAttribute('data-theme', mode);
    } else {
        root.removeAttribute('data-theme');
    }
    try {
        if (mode === 'system') {
            window.localStorage.removeItem(KEY);
        } else {
            window.localStorage.setItem(KEY, mode);
        }
    } catch {
        // Private windows may refuse storage; the theme still applies for this page.
    }
    document.querySelectorAll('[data-td-theme-switch]').forEach((button) => syncButton(button, mode));
}

function syncButton(button, mode) {
    button.dataset.mode = mode;
    const label = LABELS[mode];
    button.setAttribute('aria-label', `${label}. Change theme`);
    button.setAttribute('title', label);
}

export function initTheme(root = document) {
    root.querySelectorAll('[data-td-theme-switch]:not([data-td-ready])').forEach((button) => {
        button.dataset.tdReady = '1';
        syncButton(button, readTheme());
        button.addEventListener('click', () => {
            const next = MODES[(MODES.indexOf(readTheme()) + 1) % MODES.length];
            applyTheme(next);
        });
    });
}
