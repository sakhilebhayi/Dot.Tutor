// R-47 comms service worker: shows Web Push notifications. It caches nothing. Only a path on this site is opened on
// a tap; anything else opens the notifications page.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { data = {}; }
    const title = typeof data.title === 'string' && data.title ? data.title : 'Dot.Tutor';
    const url = typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') ? data.url : '/comms/inbox';
    event.waitUntil(self.registration.showNotification(title, {
        body: typeof data.body === 'string' ? data.body : '',
        tag: typeof data.tag === 'string' && data.tag ? data.tag : undefined,
        renotify: Boolean(data.tag),
        requireInteraction: data.critical === true,
        icon: '/favicon-32x32.png',
        data: { url },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/comms/inbox', self.location.origin);
    if (target.origin !== self.location.origin) return;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin && 'focus' in client);
        if (open) return open.navigate(target.href).then((client) => (client || open).focus()).catch(() => open.focus());
        return self.clients.openWindow(target.href);
    }));
});
