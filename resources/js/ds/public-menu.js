// Public header menu on phones (fix round D-2300). Markup: layouts/partials/public-menu-button and public-nav.
// Below 56rem the landing's four sections fold behind a Menu button: a disclosure with aria-expanded, opened by
// click, Enter or Space (never by hover). While open, Tab and Shift+Tab cycle between the button and the links (the
// same trap rule as the signed-in drawer), Escape closes and returns focus to the button, a click outside or on a
// link closes it. Without script the button stays hidden and the links show as a scrolling row (shells.css).

// The next element to focus when Tab (or Shift+Tab) leaves `active` inside the cycle `items`; null keeps the default.
export function trapTarget(items, active, backwards) {
    if (!items.length) {
        return null;
    }
    const index = items.indexOf(active);
    if (index === -1) {
        return backwards ? items[items.length - 1] : items[0];
    }
    if (backwards && index === 0) {
        return items[items.length - 1];
    }
    if (!backwards && index === items.length - 1) {
        return items[0];
    }
    return null;
}

export function initPublicMenu(root = document) {
    root.querySelectorAll('[data-public-menu]:not([data-td-ready])').forEach((button) => {
        const nav = document.getElementById(button.getAttribute('aria-controls') || '');
        const header = button.closest('.td-public-top');
        if (!nav || !header) {
            return;
        }
        button.dataset.tdReady = '1';
        button.hidden = false;
        header.setAttribute('data-menu-ready', '');

        const narrow = window.matchMedia('(max-width: 56rem)');
        const links = () => Array.from(nav.querySelectorAll('a[href]'));
        const isOpen = () => header.hasAttribute('data-menu-open');

        function set(open, returnFocus) {
            header.toggleAttribute('data-menu-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                links()[0]?.focus();
            } else if (returnFocus) {
                button.focus();
            }
        }

        button.addEventListener('click', () => set(!isOpen(), false));
        nav.addEventListener('click', (event) => {
            if (isOpen() && event.target.closest('a')) {
                set(false, false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (!isOpen()) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                set(false, true);
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const target = trapTarget([button, ...links()], document.activeElement, event.shiftKey);
            if (target) {
                event.preventDefault();
                target.focus();
            }
        });

        document.addEventListener('click', (event) => {
            if (isOpen() && !header.contains(event.target)) {
                set(false, false);
            }
        });

        const onWidth = () => {
            if (!narrow.matches && isOpen()) {
                set(false, false);
            }
        };
        if (narrow.addEventListener) {
            narrow.addEventListener('change', onWidth);
        } else {
            narrow.addListener(onWidth);
        }
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && isOpen()) {
                set(false, false);
            }
        });
    });
}
