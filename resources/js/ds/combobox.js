// Combobox (WAI-ARIA 1.2, list autocomplete). Markup from x-td.combobox: a text input with role=combobox,
// a listbox of options carrying data-value, and a hidden input that holds the chosen value for the form.
// Without script the component renders a native <select> instead (the noscript fallback in the component).
export function filterOptions(options, query) {
    const needle = query.trim().toLocaleLowerCase();
    if (!needle) {
        return options;
    }
    return options.filter((option) => option.search.includes(needle));
}

export function initComboboxes(root = document) {
    root.querySelectorAll('[data-td-combobox]:not([data-td-ready])').forEach((box) => {
        box.dataset.tdReady = '1';
        const input = box.querySelector('[role="combobox"]');
        const list = box.querySelector('[role="listbox"]');
        const hidden = box.querySelector('input[type="hidden"]');
        const empty = box.querySelector('[data-td-combobox-empty]');
        const fallback = box.querySelector('select[data-td-combobox-fallback]');
        if (!input || !list || !hidden) {
            return;
        }
        if (fallback) {
            fallback.remove();
        }
        box.querySelector('[data-td-combobox-live]')?.removeAttribute('hidden');
        hidden.disabled = false;

        const nodes = Array.from(list.querySelectorAll('[role="option"]'));
        const groups = Array.from(list.querySelectorAll('[data-td-combobox-group]'));
        const options = nodes.map((node) => ({
            node,
            value: node.dataset.value,
            label: node.dataset.label || node.textContent.trim(),
            search: (node.dataset.search || node.textContent).toLocaleLowerCase(),
        }));
        let visible = options;
        let active = -1;

        const open = () => {
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };
        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };
        const highlight = (index) => {
            visible.forEach((option, i) => option.node.classList.toggle('is-active', i === index));
            active = index;
            if (index >= 0 && visible[index]) {
                input.setAttribute('aria-activedescendant', visible[index].node.id);
                visible[index].node.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        };
        const render = () => {
            visible = filterOptions(options, input.value);
            options.forEach((option) => {
                option.node.hidden = !visible.includes(option);
            });
            // A group heading shows only while one of its options does.
            groups.forEach((heading) => {
                heading.hidden = !visible.some((option) => option.node.dataset.group === heading.dataset.tdComboboxGroup);
            });
            if (empty) {
                empty.hidden = visible.length > 0;
            }
            highlight(visible.length ? 0 : -1);
        };
        const choose = (option) => {
            hidden.value = option.value;
            input.value = option.label;
            options.forEach((o) => o.node.setAttribute('aria-selected', o === option ? 'true' : 'false'));
            close();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        };

        input.addEventListener('input', () => {
            hidden.value = '';
            open();
            render();
        });
        input.addEventListener('focus', () => {
            open();
            render();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open();
                highlight(Math.min(active + 1, visible.length - 1));
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(Math.max(active - 1, 0));
            } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
                event.preventDefault();
                choose(visible[active]);
            } else if (event.key === 'Escape') {
                close();
            }
        });
        input.addEventListener('blur', () => window.setTimeout(close, 120));
        options.forEach((option) => {
            option.node.addEventListener('mousedown', (event) => event.preventDefault());
            option.node.addEventListener('click', () => choose(option));
        });
        close();
    });
}
