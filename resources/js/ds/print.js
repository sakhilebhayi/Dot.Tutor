// Print buttons (fix round D-2307): <button data-td-print hidden> prints the page. The button stays hidden without
// script, where the page says to use the browser's own print command instead. No inline handler, so the CSP holds.
export function initPrint(root = document) {
    root.querySelectorAll('[data-td-print]:not([data-td-ready])').forEach((button) => {
        if (typeof window.print !== 'function') {
            return;
        }
        button.dataset.tdReady = '1';
        button.hidden = false;
        button.addEventListener('click', () => window.print());
        document.querySelectorAll('[data-td-print-fallback]').forEach((note) => {
            note.hidden = true;
        });
    });
}
