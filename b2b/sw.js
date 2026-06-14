/**
 * Service Worker — Push notifikace + offline support pro B2B aplikaci.
 *
 * Push notifications: zobrazí notifikaci z payloadu serveru.
 * Notification click: otevře/aktivuje záložku B2B na cílové URL.
 *
 * Cache pro PWA: zatím minimální (jen aby šlo „add to home screen").
 */
const CACHE_NAME = 'appek-b2b-v3.0.316';
const PRECACHE_URLS = [
  '/',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS).catch(() => {}))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Network-first pro HTML, ostatní bez cache (jednoduchý fallback)
self.addEventListener('fetch', (event) => {
  // Necháme browser ať fetchne normálně — push nepotřebuje agresivní cachování
  return;
});

// 🔔 PUSH event — server pošle data, my zobrazíme notifikaci
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Appek', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || 'Appek B2B';
  const options = {
    body: data.body || '',
    icon: data.icon || '/uploads/logo/favicon.png',
    badge: data.badge || data.icon || '/uploads/logo/favicon.png',
    tag: data.tag || 'appek-notification',
    requireInteraction: !!data.requireInteraction,
    data: {
      url: data.url || '/',
      ...data.data,
    },
  };
  if (data.actions) options.actions = data.actions;

  event.waitUntil(self.registration.showNotification(title, options));
});

// 🖱️ Klik na notifikaci → otevři / fokus B2B
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      // Najdi existující záložku se stejným origin
      for (const w of wins) {
        if (w.url.includes(self.location.origin) && 'focus' in w) {
          w.navigate(url);
          return w.focus();
        }
      }
      // Otevři novou
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
