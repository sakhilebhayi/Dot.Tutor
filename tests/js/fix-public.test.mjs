// Fix round (fix-public, D-2300): the phone menu's focus trap.
import test from 'node:test';
import assert from 'node:assert/strict';
import { trapTarget } from '../../resources/js/ds/public-menu.js';

const button = { id: 'button' };
const links = [{ id: 'how' }, { id: 'rooms' }, { id: 'languages' }, { id: 'teach' }];
const cycle = [button, ...links];

test('Tab from the last link returns to the menu button', () => {
    assert.equal(trapTarget(cycle, links[3], false), button);
});

test('Shift+Tab from the menu button goes to the last link', () => {
    assert.equal(trapTarget(cycle, button, true), links[3]);
});

test('Tab inside the cycle keeps the browser default', () => {
    assert.equal(trapTarget(cycle, links[0], false), null);
    assert.equal(trapTarget(cycle, links[2], true), null);
});

test('focus outside the menu is brought back in', () => {
    const outside = { id: 'elsewhere' };
    assert.equal(trapTarget(cycle, outside, false), button);
    assert.equal(trapTarget(cycle, outside, true), links[3]);
});

test('an empty cycle does nothing', () => {
    assert.equal(trapTarget([], button, false), null);
});
