import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renumber } from '../../resources/js/student/cards-editor.js';

test('a copied card row is renumbered in names and ids', () => {
    assert.equal(renumber('card[3][front]', 4), 'card[4][front]');
    assert.equal(renumber('card-3-back', 4), 'card-4-back');
    assert.equal(renumber('title', 4), 'title');
});
