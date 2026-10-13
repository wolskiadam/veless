/*
 * Service worker panelu: tylko powiadomienia push (Services\WebPush). Nic nie zapisuje w pamięci podręcznej,
 * więc panel zawsze ładuje się z serwera jak dotąd.
 */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
    var options = {
        body: data.body || '',
        icon: 'assets/app-icon-192.png',
        badge: 'assets/app-icon-192.png',
        data: { url: data.url || 'index.php' }
    };
    if (data.tag) { options.tag = data.tag; }
    event.waitUntil(self.registration.showNotification(data.title || 'Veless', options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = new URL((event.notification.data && event.notification.data.url) || 'index.php', self.registration.scope).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
                return c.focus().then(function (w) { return w && 'navigate' in w ? w.navigate(url) : w; });
            }
        }
        return self.clients.openWindow(url);
    }));
});
