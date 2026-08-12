// sw.js - Service Worker for Eaprimus PWA & Web Push Notifications
const CACHE_NAME = 'eaprimus-v2';
const ASSETS = [
    './',
    'anasayfa',
    'manifest.json',
    'public/favicon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(ASSETS).catch(() => {});
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (!event.request.url.startsWith('http://') && !event.request.url.startsWith('https://')) return;

    // Do not intercept API streams, live_stream.php or ajax calls
    if (event.request.url.includes('/api/') || event.request.url.includes('live_stream') || event.request.url.includes('ajax')) {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(async () => {
            const cachedResponse = await caches.match(event.request);
            if (cachedResponse) {
                return cachedResponse;
            }
            return Response.error();
        })
    );
});

// Native Push Notification Listener in Service Worker
self.addEventListener('push', event => {
    let data = { title: 'Eaprimus Notification', body: 'New notification received', url: '/' };
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: 'public/favicon.png',
        badge: 'public/favicon.png',
        data: { url: data.url || '/' },
        vibrate: [200, 100, 200]
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const targetUrl = event.notification.data ? event.notification.data.url : '/';
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (let client of clientList) {
                if ('focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
