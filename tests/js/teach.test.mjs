import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toMinor, formatMinor } from '../../resources/js/teach/split.js';

test('prices typed in rands become minor units', () => {
    assert.equal(toMinor('250'), 25000);
    assert.equal(toMinor('250.5'), 25050);
    assert.equal(toMinor('250,05'), 25005);
    assert.equal(toMinor(' 0 '), 0);
    assert.equal(toMinor('12.345'), null);
    assert.equal(toMinor('R250'), null);
    assert.equal(toMinor(''), null);
});

test('minor units format in the kit money style', () => {
    assert.equal(formatMinor(125050), 'R1 250.50');
    assert.equal(formatMinor(0), 'R0.00');
    assert.equal(formatMinor(-3750), '−R37.50');
    assert.equal(formatMinor(100000000, 'USD'), '$1 000 000.00');
});
