// Stacked row cards for data tables on phones (x-td.table stack, D-2202). Without script the table scrolls in its
// labelled region as before; with script each cell learns its column's header (data-label) and its role, and under
// 40rem the CSS lays every row out as a card: the title and the key figure on line 1, the other fields as labelled
// pairs below, the action across the foot. Nothing is dropped.
//
// Roles come from the header cell: <th data-stack="key"> for the figure that matters (You keep, Total, Respond by),
// data-stack="action" for the row's buttons, data-stack="wide" for long text. The first column is the title unless
// a header says otherwise.

/**
 * Header label and role for each column of a header row, colspans expanded.
 *
 * @param {{text: string, role: string|null, span: number}[]} headers
 * @returns {{label: string, role: string|null}[]}
 */
export function columnMap(headers) {
    const columns = [];
    headers.forEach((header) => {
        for (let i = 0; i < Math.max(1, header.span || 1); i += 1) {
            columns.push({ label: header.text.replace(/\s+/g, ' ').trim(), role: header.role || null });
        }
    });
    if (columns.length && !columns.some((c) => c.role === 'title')) {
        columns[0] = { ...columns[0], role: columns[0].role || 'title' };
    }
    return columns;
}

/**
 * Label and role for every cell of a row, from the column map; a cell spanning columns takes the first one's.
 *
 * @param {number[]} spans colspan of each cell in the row
 * @param {{label: string, role: string|null}[]} columns
 * @returns {{label: string, role: string|null}[]}
 */
export function cellRoles(spans, columns) {
    let index = 0;
    return spans.map((span) => {
        const width = Math.max(1, span || 1);
        const covered = columns.slice(index, index + width);
        const first = covered[0] || { label: '', role: null };
        index += width;
        if (width === 1) {
            return { label: first.label, role: first.role };
        }
        // A cell across several columns (a totals label, an empty state) is the title when it covers the title
        // column, and otherwise runs the full width.
        return { label: first.label, role: covered.some((c) => c.role === 'title') ? 'title' : 'wide' };
    });
}

export function initTableStacks(root = document) {
    root.querySelectorAll('table[data-td-stack]:not([data-td-ready])').forEach((table) => {
        table.dataset.tdReady = '1';
        const headRow = table.tHead?.rows[table.tHead.rows.length - 1];
        if (!headRow) {
            return;
        }
        const columns = columnMap(Array.from(headRow.cells).map((cell) => ({
            text: cell.dataset.stackLabel ?? cell.textContent,
            role: cell.dataset.stack || null,
            span: cell.colSpan,
        })));
        const rows = [...Array.from(table.tBodies).flatMap((body) => Array.from(body.rows)), ...(table.tFoot ? Array.from(table.tFoot.rows) : [])];
        rows.forEach((row) => {
            const cells = Array.from(row.cells);
            cellRoles(cells.map((cell) => cell.colSpan), columns).forEach(({ label, role }, i) => {
                const cell = cells[i];
                if (!cell.hasAttribute('data-label')) {
                    cell.dataset.label = label;
                }
                if (role && !cell.dataset.stack) {
                    cell.dataset.stack = role;
                }
            });
        });
        table.classList.add('is-stackable');
    });
}
