import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pushSupport, keyToBytes, MESSAGES } from '../../public/comms/push.js';

const desktop = { hasServiceWorker: true, hasPushManager: true, hasNotification: true, userAgent: 'Mozilla/5.0 (Windows NT 10.0) Chrome/130', permission: 'default' };

test('push support states are honest', () => {
    assert.equal(pushSupport(desktop), 'available');
    assert.equal(pushSupport({ ...desktop, permission: 'denied' }), 'blocked');
    assert.equal(pushSupport({ ...desktop, hasPushManager: false }), 'unsupported');
    assert.equal(pushSupport({ ...desktop, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1' }), 'ios_needs_home_screen');
    assert.equal(pushSupport({ ...desktop, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', standalone: true }), 'available');
    for (const state of ['unsupported', 'blocked', 'ios_needs_home_screen']) assert.ok(MESSAGES[state].length > 20);
});

test('a base64url VAPID key decodes to its 65 raw bytes', () => {
    const bytes = new Uint8Array(65).map((_, i) => (i * 7) % 256);
    bytes[0] = 4;
    const b64url = Buffer.from(bytes).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    assert.deepEqual(Array.from(keyToBytes(b64url)), Array.from(bytes));
});
