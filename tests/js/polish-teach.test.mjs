import { test } from 'node:test';
import assert from 'node:assert/strict';
import { columnMap, cellRoles } from '../../resources/js/ds/table-stack.js';
import { centredScroll } from '../../resources/js/ds/tabs-overflow.js';

test('the first column is the title unless a header names another one', () => {
    const plain = columnMap([{ text: ' Offering ', span: 1 }, { text: 'You keep', role: 'key', span: 1 }, { text: 'Actions', role: 'action', span: 1 }]);
    assert.deepEqual(plain.map((c) => c.role), ['title', 'key', 'action']);
    assert.equal(plain[0].label, 'Offering');

    const named = columnMap([{ text: 'Date', span: 1 }, { text: 'Item', role: 'title', span: 1 }]);
    assert.deepEqual(named.map((c) => c.role), [null, 'title']);
});

test('cells take their column header; a spanning cell is the title when it covers the title column, else wide', () => {
    const columns = columnMap([
        { text: 'Date', span: 1 }, { text: 'Item', role: 'title', span: 1 }, { text: 'Price', span: 1 }, { text: 'You keep', role: 'key', span: 1 },
    ]);
    assert.deepEqual(cellRoles([1, 1, 1, 1], columns), [
        { label: 'Date', role: null }, { label: 'Item', role: 'title' }, { label: 'Price', role: null }, { label: 'You keep', role: 'key' },
    ]);
    // "Period totals" across Date and Item, then the figures.
    assert.deepEqual(cellRoles([2, 1, 1], columns).map((c) => c.role), ['title', null, 'key']);
    // An empty-state cell across everything.
    assert.equal(cellRoles([4], columnMap([{ text: 'A', span: 1 }, { text: 'B', role: 'title', span: 1 }, { text: 'C', span: 2 }]))[0].role, 'title');
    assert.equal(cellRoles([1, 2], columnMap([{ text: 'A', span: 1 }, { text: 'B', span: 1 }, { text: 'C', span: 1 }]))[1].role, 'wide');
});

test('the current tab is centred in its row and the scroll stays inside the row', () => {
    assert.equal(centredScroll(600, 100, 300, 1000), 500);
    assert.equal(centredScroll(20, 100, 300, 1000), 0);
    assert.equal(centredScroll(950, 50, 300, 1000), 700);
    assert.equal(centredScroll(50, 50, 400, 300), 0);
});
