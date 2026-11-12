// Tabs with panels (WAI-ARIA tabs pattern, automatic activation). Markup: a [role=tablist][data-td-tabs]
// whose [role=tab] buttons name their panel with aria-controls. Without script every panel shows.
export function initTabs(root = document) {
    root.querySelectorAll('[data-td-tabs]:not([data-td-ready])').forEach((list) => {
        list.dataset.tdReady = '1';
        const tabs = Array.from(list.querySelectorAll('[role="tab"]'));
        if (!tabs.length) {
            return;
        }
        const vertical = list.getAttribute('aria-orientation') === 'vertical';

        const select = (tab, focus = true) => {
            tabs.forEach((other) => {
                const on = other === tab;
                other.setAttribute('aria-selected', on ? 'true' : 'false');
                other.tabIndex = on ? 0 : -1;
                const panel = document.getElementById(other.getAttribute('aria-controls'));
                if (panel) {
                    panel.hidden = !on;
                }
            });
            if (focus) {
                tab.focus();
            }
            list.dispatchEvent(new CustomEvent('td-tab-change', { detail: { tab }, bubbles: true }));
        };

        const current = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];
        select(current, false);

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => select(tab, false));
            tab.addEventListener('keydown', (event) => {
                const next = vertical ? 'ArrowDown' : 'ArrowRight';
                const previous = vertical ? 'ArrowUp' : 'ArrowLeft';
                let target = null;
                if (event.key === next) {
                    target = tabs[(index + 1) % tabs.length];
                } else if (event.key === previous) {
                    target = tabs[(index - 1 + tabs.length) % tabs.length];
                } else if (event.key === 'Home') {
                    target = tabs[0];
                } else if (event.key === 'End') {
                    target = tabs[tabs.length - 1];
                }
                if (target) {
                    event.preventDefault();
                    select(target);
                }
            });
        });
    });
}
