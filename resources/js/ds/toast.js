// Toasts: window.tdToast('Saved', 'success') or dispatch a 'td-toast' event with {message, tone}.
// The region is a polite live region; errors are assertive and stay until dismissed; others leave after
// six seconds unless the pointer or focus is on them. Only call it after the server confirmed the action.
const LIFETIME = 6000;

function region() {
    return document.querySelector('[data-td-toasts]');
}

export function showToast(message, tone = 'info') {
    const host = region();
    if (!host || !message) {
        return null;
    }
    const toast = document.createElement('div');
    toast.className = `td-toast td-toast--${tone}`;
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    const text = document.createElement('p');
    text.className = 'td-toast__text';
    text.style.margin = '0';
    text.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'td-btn td-btn--quiet td-btn--icon';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '<svg class="td-icon" aria-hidden="true"><use href="/icons/td-sprite.svg#i-x"></use></svg>';
    close.addEventListener('click', () => toast.remove());

    toast.append(text, close);
    host.append(toast);

    if (tone !== 'error') {
        let timer = window.setTimeout(() => toast.remove(), LIFETIME);
        const pause = () => window.clearTimeout(timer);
        const resume = () => {
            timer = window.setTimeout(() => toast.remove(), LIFETIME);
        };
        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('focusin', pause);
        toast.addEventListener('mouseleave', resume);
        toast.addEventListener('focusout', resume);
    }

    return toast;
}

export function initToasts(root = document) {
    if (!window.tdToast) {
        window.tdToast = showToast;
        window.addEventListener('td-toast', (event) => showToast(event.detail?.message, event.detail?.tone));
    }
    // Server-rendered toasts (flash messages) get the same dismiss and timing behaviour.
    root.querySelectorAll('[data-td-toast]:not([data-td-ready])').forEach((node) => {
        node.dataset.tdReady = '1';
        const message = node.dataset.tdToast;
        const tone = node.dataset.tdTone || 'info';
        node.remove();
        showToast(message, tone);
    });
}
