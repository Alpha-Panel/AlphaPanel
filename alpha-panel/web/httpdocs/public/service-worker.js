self.addEventListener('push', function (event) {
    if (!event.data) {
        return;
    }

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = { title: 'AlphaPanel', body: event.data.text() };
    }

    const title = data.title || 'AlphaPanel';
    const options = {
        body: data.body || '',
        icon: data.icon || '/img/android-icon-192x192.png',
        badge: '/img/android-icon-96x96.png',
        data: data.data || {},
        tag: data.tag || 'alphapanel-notification',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const url = event.notification.data?.url;

    if (url) {
        event.waitUntil(
            clients
                .matchAll({ type: 'window', includeUncontrolled: true })
                .then(function (clientList) {
                    for (const client of clientList) {
                        if (client.url === url && 'focus' in client) {
                            return client.focus();
                        }
                    }
                    return clients.openWindow(url);
                }),
        );
    }
});
