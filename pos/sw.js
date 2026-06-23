/**
 * Service Worker — POS (minimální, jen pro PWA installability "add to home screen" na Androidu).
 * 🆕 v3.0.362. Fetch handler je BEZ interceptu (`return;`) = žádné agresivní cachování →
 * POS vždy bere čerstvý kód z network (žádný stale-POS risk). Listener existuje jen proto,
 * aby Chrome nabídl instalaci. CACHE_NAME bumpuje build-update.sh (úklid starých verzí v activate).
 */
const CACHE_NAME = 'appek-pos-v3.0.385';
const PRECACHE_URLS = ['/pos/', '/pos/manifest.json'];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE_NAME).then((c) => c.addAll(PRECACHE_URLS).catch(() => {})));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k.startsWith('appek-pos-') && k !== CACHE_NAME).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// Bez interceptu — browser fetchuje normálně (žádný stale POS). Listener jen kvůli installability.
self.addEventListener('fetch', () => { return; });
