// R-47 comms: turn Web Push on or off for this browser (preferences page). Served as a static module (no Node at
// runtime); the pure helpers are exported for tests/js/comms.test.mjs.

/** What this browser can do. 'ios_needs_home_screen': iPhone/iPad Safari only allows push from a home-screen app. */
export function pushSupport(env) {
    const { hasServiceWorker, hasPushManager, hasNotification, userAgent = '', standalone = false, permission } = env;
    const ios = /iPhone|iPad|iPod/.test(userAgent);
    if (ios && !standalone) return 'ios_needs_home_screen';
    if (!hasServiceWorker || !hasPushManager || !hasNotification) return 'unsupported';
    if (permission === 'denied') return 'blocked';
    return 'available';
}

/** Base64url (VAPID public key) to the bytes PushManager.subscribe wants. */
export function keyToBytes(base64url) {
    const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

export const MESSAGES = {
    unsupported: 'This browser cannot receive push notifications. Email and your notifications here still work.',
    ios_needs_home_screen: 'On iPhone and iPad, add Dot.Tutor to your home screen first (Share, then Add to Home Screen), open it from there, and turn push on.',
    blocked: 'Push is blocked for this site in your browser settings. Allow notifications for this site there, then come back.',
    off: 'Push is off on this device.',
    on: 'Push is on for this device.',
    working: 'One moment…',
    failed: 'Push could not be turned on. Try again, or use email.',
};

async function init(root) {
    const status = root.querySelector('[data-comms-push-status]');
    const onBtn = root.querySelector('[data-comms-push-on]');
    const offBtn = root.querySelector('[data-comms-push-off]');
    const testBtn = root.querySelector('[data-comms-push-test]');
    const say = (text) => { status.textContent = text; };
    const show = (subscribed) => {
        onBtn.hidden = subscribed;
        offBtn.hidden = !subscribed;
        testBtn.hidden = !subscribed;
        say(subscribed ? MESSAGES.on : MESSAGES.off);
    };
    const send = (url, method, body) => fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

    const support = pushSupport({
        hasServiceWorker: 'serviceWorker' in navigator,
        hasPushManager: 'PushManager' in window,
        hasNotification: 'Notification' in window,
        userAgent: navigator.userAgent,
        standalone: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        permission: 'Notification' in window ? Notification.permission : 'default',
    });
    if (support !== 'available') { say(MESSAGES[support]); return; }

    const registration = await navigator.serviceWorker.register('/comms-sw.js', { scope: '/' });
    const existing = await registration.pushManager.getSubscription();
    show(Boolean(existing));

    onBtn.addEventListener('click', async () => {
        say(MESSAGES.working);
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') { say(MESSAGES.blocked); return; }
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(root.dataset.key) });
            const response = await send(root.dataset.subscribe, 'POST', subscription.toJSON());
            if (!response.ok) {
                await subscription.unsubscribe();
                const body = await response.json().catch(() => ({}));
                say(body.message || MESSAGES.failed);
                return;
            }
            show(true);
        } catch {
            say(MESSAGES.failed);
        }
    });

    offBtn.addEventListener('click', async () => {
        say(MESSAGES.working);
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await send(root.dataset.subscribe, 'DELETE', { endpoint: subscription.endpoint });
            await subscription.unsubscribe();
        }
        show(false);
    });

    testBtn.addEventListener('click', async () => {
        say(MESSAGES.working);
        const response = await send(root.dataset.test, 'POST');
        const body = await response.json().catch(() => ({}));
        const devices = body.devices || [];
        const sent = devices.filter((d) => d.status === 'sent').length;
        say(response.ok ? `The push service accepted the test for ${sent} of ${devices.length} device${devices.length === 1 ? '' : 's'}.` : (body.message || MESSAGES.failed));
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-comms-push]').forEach((root) => { init(root).catch(() => {}); });
}
