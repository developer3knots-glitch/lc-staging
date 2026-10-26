/**
 * Admin browser notifications (visitor land / chat alerts).
 * A visible admin tab handles its own toast. A minimized or background
 * tab still gets the phone/browser notification.
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
                var client = clientList[i];
                var url = client && client.url ? client.url : '';
                var onAdmin = url.indexOf('/admin/') !== -1 || url.indexOf('/admin?') !== -1;
                if (!onAdmin) continue;
                // Minimized mobile Chrome can still report the tab as visible.
                // Only skip the banner when this admin window is actually focused.
                if (client.focused === true) {
                    return;
                }
                if (client.focused !== false && client.visibilityState === 'visible') {
                    return;
                }
            }
            var target = data.url || '/admin/chats.php';
            var quiet = data.silent === true;
            return self.registration.showNotification(data.title || 'Live chat', {
                body: data.body || '',
                tag: data.tag || ('lc-push-' + Date.now()),
                renotify: !quiet,
                silent: quiet,
                vibrate: quiet ? undefined : [180, 80, 180],
                timestamp: Date.now(),
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
