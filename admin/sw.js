/**
 * 🛠️ APPEK SERVICE WORKER — PWA offline + caching
 *
 * 🆕 v2.0.75 — FIX: po update přestal servovat starý admin.js (stale cache).
 *
 * Strategie:
 *   - URLs s ?v= query → CACHE-BUSTING aware: cache key includes query
 *     → změna ?v=2.0.73 → ?v=2.0.74 = nový cache entry, starý se ignoruje
 *   - Static assets bez ?v= → stale-while-revalidate (rychlý load, update v pozadí)
 *   - HTML pages → network-first (vždy fresh navigation)
 *   - API calls → network-only (žádný cache)
 *   - CLAIM ihned po install → starý SW neslouží staré assety
 */

const CACHE_VERSION = 'appek-v3.0.313';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const DYNAMIC_CACHE = `${CACHE_VERSION}-dynamic`;

// Soubory cachované při install (basic shell)
// 🆕 v3.0.41 — přidána offline.html jako fancy offline fallback page
const PRECACHE_URLS = [
  './',
  './index.html',
  './offline.html',
  './manifest.json',
];

// ─── INSTALL — okamžitě skipWaiting, ať nový SW převezme ihned ──
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS).catch(() => null))
      .then(() => self.skipWaiting())
  );
});

// ─── ACTIVATE — smaž VŠECHNY staré cache + claim ────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => !k.startsWith(CACHE_VERSION))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ─── FETCH — strategie podle typu requestu ───────────────────────
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Jen GET
  if (req.method !== 'GET') return;

  // API calls → network-only (žádný cache)
  if (url.pathname.startsWith('/api/')) {
    return; // default network behavior
  }

  // Cross-origin → default
  if (url.origin !== self.location.origin) return;

  // HTML → network-first
  if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(req)
        .then((resp) => {
          if (resp.ok) {
            const respClone = resp.clone();
            caches.open(DYNAMIC_CACHE).then((c) => c.put(req, respClone));
          }
          return resp;
        })
        // 🆕 v3.0.41 — offline.html jako fancy fallback (s reload button + status badge)
        .catch(() => caches.match(req).then((r) => r || caches.match('./offline.html') || caches.match('./index.html')))
    );
    return;
  }

  // 🆕 v2.0.75 — Versioned assety (s ?v=X.Y.Z query) → NETWORK-FIRST
  // Cache buster = invalidace, ale musíme FETCH nový soubor, ne servovat starý.
  const hasVersionQuery = url.searchParams.has('v');
  if (hasVersionQuery) {
    event.respondWith(
      fetch(req)
        .then((resp) => {
          if (resp.ok) {
            const respClone = resp.clone();
            caches.open(STATIC_CACHE).then((c) => c.put(req, respClone));
          }
          return resp;
        })
        .catch(() => caches.match(req))  // offline fallback
    );
    return;
  }

  // Bezversionované static assety (favicony, ikony) → stale-while-revalidate
  event.respondWith(
    caches.match(req).then((cached) => {
      const fetchPromise = fetch(req).then((resp) => {
        if (resp.ok) {
          const respClone = resp.clone();
          caches.open(STATIC_CACHE).then((c) => c.put(req, respClone));
        }
        return resp;
      }).catch(() => cached);
      return cached || fetchPromise;
    })
  );
});

// ─── PUSH notifikace (pokud customer povolí) ────────────────────
self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (e) { data = { title: 'APPEK', body: event.data.text() }; }

  const title = data.title || 'APPEK';
  const options = {
    body: data.body || '',
    icon: data.icon || './icons/icon-192.svg',
    badge: './icons/icon-192.svg',
    data: data.url ? { url: data.url } : {},
    requireInteraction: data.urgent || false,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

// ─── Notification click → otevři URL ────────────────────────────
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const win of wins) {
        if (win.url.includes(url) && 'focus' in win) return win.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

// ─── Message handler — admin může poslat SKIP_WAITING nebo CLEAR_CACHE ──
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data?.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k))))
        .then(() => {
          // Notifikuj všechny klienty že cache je vyčištěná → můžou reloadnout
          self.clients.matchAll().then(clients =>
            clients.forEach(c => c.postMessage({ type: 'CACHE_CLEARED' }))
          );
        })
    );
  }
});
