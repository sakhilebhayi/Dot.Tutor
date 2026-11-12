// R-47 fix round (fix-ops, D-2405): the safeguarding incident's response countdown. Times are absolute instants
// (Date.UTC), so the result does not depend on the machine's time zone.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { countdownText, spanWords } from '../../resources/js/ds/countdown.js';

const at = Date.UTC(2026, 10, 13, 12, 0, 0);

test('minutes left before the deadline', () => {
    assert.equal(countdownText(at, at - 41 * 60000 - 5000, 'Acknowledge within', 'Overdue by'), 'Acknowledge within 41 min');
});

test('hours once two or more are left', () => {
    assert.equal(countdownText(at, at - (3 * 60 + 5) * 60000, 'Acknowledge within', 'Overdue by'), 'Acknowledge within 3 h 5 min');
});

test('overdue once the deadline has passed', () => {
    assert.equal(countdownText(at, at + 10 * 60000, 'Acknowledge within', 'Overdue by'), 'Overdue by 10 min');
    assert.equal(spanWords(0), '0 min');
});
