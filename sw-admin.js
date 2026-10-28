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
            }
            var target = data.url || '/admin/chats.php';
            var quiet = data.silent === true;
            var options = {
                body: data.body || '',
                tag: (data.tag || 'lc-push') + '-' + Date.now(),
                timestamp: Date.now(),
                renotify: true,
                data: { url: target }
            };
            if (quiet) {
                options.silent = true;
                options.renotify = false;
                options.tag = data.tag || ('lc-push-' + Date.now());
            }
            return self.registration.showNotification(data.title || 'Live chat', options);
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var data = event.notification.data || {};
    var raw = data.url || '/admin/chats.php';
    var target;
    try {
        target = new URL(raw, self.location.origin).href;
    } catch (eUrl) {
        target = self.location.origin + '/admin/chats.php';
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            var admin = [];
            var i;
            for (i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                var url = client && client.url ? client.url : '';
                if (url.indexOf('/admin/') !== -1 || url.indexOf('/admin?') !== -1) {
                    admin.push(client);
                }
            }
            var chosen = null;
            for (i = 0; i < admin.length; i++) {
                if (admin[i].focused === true) {
                    chosen = admin[i];
                    break;
                }
            }
            if (!chosen && admin.length) {
                chosen = admin[0];
            }
            if (chosen && 'focus' in chosen) {
                return chosen.focus().then(function (focused) {
                    var page = focused || chosen;
                    if (page && typeof page.postMessage === 'function') {
                        page.postMessage({ type: 'lc-open-url', url: target });
                    }
                    return page;
                });
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(target);
            }
        })
    );
});
