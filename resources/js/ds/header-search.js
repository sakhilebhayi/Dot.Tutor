// The header search dropdown (R-52). The pill is the shared dot-ui search (js/dot-search.js opens and closes it, js/dot-search-results.js owns the
// keyboard, the loading line and the fetch loop). This file only says WHERE to ask: /search/suggest, scoped to the shell the person is in. The server
// re-checks the scope on every request (TutorSearch::resolveScope), so nothing here is trusted. Without script the pill is a plain GET form to /search.
const MIN = 2;

export function initHeaderSearch(root = document) {
    const search = window.DotSearchResults;
    if (!search) {
        // This module (deferred, in <head>) runs BEFORE the classic deferred scripts at the end of <body>, so js/dot-search-results.js may not have
        // run yet. DOMContentLoaded fires after every deferred script: try again then, once.
        if (document.readyState === 'loading' || !root.__tdSearchRetry) {
            root.__tdSearchRetry = true;
            document.addEventListener('DOMContentLoaded', () => initHeaderSearch(root), { once: true });
        }
        return;
    }

    root.querySelectorAll('.dsr[data-tutor-search]:not([data-tutor-search-ready])').forEach((box) => {
        const url = box.getAttribute('data-suggest-url');
        const form = box.querySelector('form');
        const input = box.querySelector('.dsr-input');
        if (!url || !form || !input) {
            return;
        }
        box.setAttribute('data-tutor-search-ready', '');
        const pages = box.querySelector('[data-search-pages]');
        const scopeField = form.elements.namedItem('scope');
        const scope = scopeField && 'value' in scopeField ? scopeField.value : '';

        // "Go to" (the shell's own pages) shows while the box is empty; once there is text the live rows take its place.
        const syncPages = () => {
            if (pages) {
                pages.hidden = input.value.trim().length >= 1;
            }
        };
        input.addEventListener('input', syncPages);
        syncPages();

        search.attach(box, {
            debounce: 250,
            minChars: MIN,
            empty: 'Nothing matches. Try fewer words, a name or a subject.',
            search: async (q, { signal }) => {
                const params = new URLSearchParams({ q, scope });
                const response = await fetch(`${url}?${params}`, { signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) {
                    throw new Error(`search ${response.status}`);
                }
                const body = await response.json();
                const groups = Array.isArray(body.groups) ? body.groups : [];
                if (groups.length === 0) {
                    return groups;
                }
                const all = new URL(form.getAttribute('action'), window.location.origin);
                all.search = params.toString();

                return groups.concat([{ head: 'More', rows: [{ href: all.pathname + all.search, label: `See every result for ${q}`, action: true }] }]);
            },
        });
    });
}
