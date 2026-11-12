// Flashcard editor (R-47 polish D-2021): "Add another card" copies the last empty pair of fields and renumbers it.
// Without script the form shows a fixed number of pairs, which still saves.

/** Rename "card[3][front]" to "card[4][front]" and "card-3-front" to "card-4-front". */
export function renumber(value, index) {
    return String(value).replace(/card\[\d+\]/, `card[${index}]`).replace(/card-\d+-/, `card-${index}-`);
}

export function cardsEditor(root) {
    root.querySelectorAll('[data-cards-editor]').forEach((editor) => {
        const list = editor.querySelector('[data-cards-list]');
        const add = editor.querySelector('[data-cards-add]');
        if (!list || !add) return;
        add.hidden = false;
        add.addEventListener('click', () => {
            const rows = list.querySelectorAll('[data-card-row]');
            const last = rows[rows.length - 1];
            if (!last || rows.length >= 200) return;
            const index = rows.length;
            const row = last.cloneNode(true);
            row.querySelectorAll('[name], [id], [for]').forEach((el) => {
                ['name', 'id', 'for'].forEach((attr) => {
                    if (el.hasAttribute(attr)) el.setAttribute(attr, renumber(el.getAttribute(attr), index));
                });
                if ('value' in el && el.tagName !== 'LABEL') el.value = '';
            });
            const number = row.querySelector('[data-card-number]');
            if (number) number.textContent = String(index + 1);
            list.appendChild(row);
            row.querySelector('input, textarea')?.focus();
        });
    });
}
