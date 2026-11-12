// A form marked data-st-autosubmit reloads when one of its selects changes (choosing a level shows its subjects).
// Without script the form keeps its visible submit button; with script that button is hidden.
export function autosubmit(root) {
    root.querySelectorAll('form[data-st-autosubmit]').forEach((form) => {
        form.querySelectorAll('[data-st-noscript-only]').forEach((el) => {
            el.hidden = true;
        });
        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    });
}
