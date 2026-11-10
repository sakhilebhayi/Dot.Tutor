// The whiteboard's client-side rounding must match the server's (Whiteboard::point: clamp to 0..1, 4 decimals), or a
// person's own stroke would be drawn twice when the poll brings it back.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normPoint, opKey } from '../../resources/js/classroom/board.js';
import { fmtClock } from '../../resources/js/classroom/util.js';

test('points are clamped to the page and rounded to four decimals', () => {
    assert.deepEqual(normPoint(0.123456, 1.7), [0.1235, 1]);
    assert.deepEqual(normPoint(-0.2, 0.5), [0, 0.5]);
});

test('an op key is the same for the pending copy and the copy the server returns', () => {
    const pending = { points: [normPoint(0.1, 0.2), normPoint(0.33333, 0.9)], width: 4, colour: 'ink' };
    const fromServer = JSON.parse(JSON.stringify({ points: [[0.1, 0.2], [0.3333, 0.9]], width: 4, colour: 'ink' }));
    assert.equal(opKey(1, 'pen', pending), opKey(1, 'pen', fromServer));
    assert.notEqual(opKey(1, 'pen', pending), opKey(2, 'pen', fromServer));
});

test('the lesson clock reads minutes and seconds, then hours', () => {
    assert.equal(fmtClock(75), '01:15');
    assert.equal(fmtClock(3725), '1:02:05');
});
