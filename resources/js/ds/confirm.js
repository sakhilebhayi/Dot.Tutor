// A form with data-confirm="question" asks before it submits. Replaces inline onsubmit handlers, which the enforced
// Content-Security-Policy blocks (R-47 security D-1514). Without script the form simply submits.
export function initConfirm() {
    if (document.documentElement.dataset.tdConfirm === 'on') {
        return;
    }
    document.documentElement.dataset.tdConfirm = 'on';
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const question = form instanceof HTMLFormElement ? form.dataset.confirm : undefined;
        if (question && !window.confirm(question)) {
            event.preventDefault();
        }
    }, true);
}
