/**
 * Admin desktop notifications (visitor land / chat alerts).
 * Works while any admin tab is open — agent Online status is NOT required.
 */
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {};
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var url = clientList[i] && clientList[i].url ? clientList[i].url : '';
                if (url.indexOf('/admin/') !== -1 || url.indexOf('/admin?') !== -1) {
                    return;
                }
            }
            var target = data.url || '/admin/chats.php';
            return self.registration.showNotification(data.title || 'Live chat', {
                body: data.body || '',
                tag: data.tag || 'lc-push',
                renotify: false,
                silent: data.silent === true,
                data: { url: target }
            });
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var data = event.notification.data || {};
    var url = data.url || '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url && 'focus' in client) {
                    try {
                        if (data.url && client.navigate) {
                            client.navigate(data.url);
                        }
                    } catch (eNav) { /* ignore */ }
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
