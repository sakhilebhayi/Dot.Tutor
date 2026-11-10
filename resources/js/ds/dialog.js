// Native <dialog> openers and closers. [data-td-dialog-open="id"] opens a modal; [data-td-dialog-close]
// closes the dialog it sits in. The browser handles the focus trap, Escape and returning focus.
export function initDialogs(root = document) {
    root.querySelectorAll('[data-td-dialog-open]:not([data-td-ready])').forEach((opener) => {
        opener.dataset.tdReady = '1';
        opener.addEventListener('click', (event) => {
            const dialog = document.getElementById(opener.dataset.tdDialogOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                event.preventDefault();
                dialog.showModal();
            }
        });
    });

    root.querySelectorAll('dialog.td-dialog:not([data-td-ready])').forEach((dialog) => {
        dialog.dataset.tdReady = '1';
        dialog.querySelectorAll('[data-td-dialog-close]').forEach((closer) => {
            closer.addEventListener('click', () => dialog.close(closer.value || 'cancel'));
        });
        // A click on the backdrop (the dialog element itself, outside its content) closes it.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog && dialog.dataset.tdDismissible !== 'false') {
                dialog.close('cancel');
            }
        });
    });
}
