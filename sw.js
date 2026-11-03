/* Sorpresa — service worker : l'appli s'ouvre même hors connexion,
   et se met à jour d'elle-même (le réseau d'abord, la copie en secours).
   Les échanges avec le serveur ne sont jamais mis en cache. */
const VERSION = 'sorpresa-1';
const FICHIERS = ['./', './index.html', './manifest.webmanifest',
  './icones/icon-192.png', './icones/icon-512.png', './icones/apple-touch-icon.png', './icones/favicon-32.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((cles) => Promise.all(cles.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== location.origin) return;
  e.respondWith(
    fetch(req)
      .then((rep) => {
        if (rep.ok) { const copie = rep.clone(); caches.open(VERSION).then((c) => c.put(req, copie)); }
        return rep;
      })
      .catch(() => caches.match(req, { ignoreSearch: true }).then((r) => r || caches.match('./index.html')))
  );
});
