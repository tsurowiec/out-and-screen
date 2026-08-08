// Service worker for Out&Screen. Its only real job is receiving Web Push, but
// it also has to take over immediately — an old worker left in control would go
// on using an old push handler.

self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
    let payload = {}

    try {
        payload = event.data ? event.data.json() : {}
    } catch (e) {
        // A push with no usable body still deserves to surface: iOS drops the
        // subscription if a push arrives and no notification is shown.
    }

    const title = payload.title || 'Out&Screen'

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            // Reusing one tag means a second session ending replaces the first
            // notification instead of stacking up.
            tag: payload.tag || 'out-and-screen',
            renotify: true,
            icon: '/icon-192.png',
            badge: '/icon-192.png',
            data: { url: payload.url || '/dashboard' },
        }),
    )
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()

    const target = (event.notification.data && event.notification.data.url) || '/dashboard'

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            // Prefer focusing the window that's already open over spawning a
            // second copy of the app.
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(target)

                    return client.focus()
                }
            }

            return self.clients.openWindow(target)
        }),
    )
})
