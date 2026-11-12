// Tab rows that are wider than the screen (D-2203). The row already scrolls sideways in its own lane; this makes that
// visible and keeps the current tab in view: the active tab is scrolled to the middle of the row on load, and an edge
// fades out on each side that has more tabs (data-more-start / data-more-end), so "Check and publish" can be found.

/** Scroll position that centres a tab in its row, clamped to the row's range. */
export function centredScroll(tabLeft, tabWidth, rowWidth, scrollWidth) {
    const target = tabLeft - (rowWidth - tabWidth) / 2;
    return Math.max(0, Math.min(target, Math.max(0, scrollWidth - rowWidth)));
}

export function initTabOverflow(root = document) {
    root.querySelectorAll('.td-tabs:not([data-td-overflow-ready])').forEach((row) => {
        row.dataset.tdOverflowReady = '1';
        const centre = (tab) => {
            if (row.scrollWidth <= row.clientWidth) {
                return;
            }
            const left = tab.getBoundingClientRect().left - row.getBoundingClientRect().left + row.scrollLeft;
            row.scrollLeft = centredScroll(left, tab.offsetWidth, row.clientWidth, row.scrollWidth);
        };
        const update = () => {
            const max = row.scrollWidth - row.clientWidth;
            row.toggleAttribute('data-more-start', row.scrollLeft > 2);
            row.toggleAttribute('data-more-end', max - row.scrollLeft > 2);
        };
        const current = row.querySelector('[aria-current="page"], [aria-selected="true"]');
        if (current) {
            centre(current);
        }
        update();
        row.addEventListener('scroll', update, { passive: true });
        row.addEventListener('focusin', (event) => {
            const tab = event.target.closest('.td-tab');
            if (tab) {
                centre(tab);
            }
        });
        if ('ResizeObserver' in window) {
            new ResizeObserver(update).observe(row);
        }
    });
}
