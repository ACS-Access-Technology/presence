/* Presence — service worker minimal pour la page publique d'émargement.
   Cache uniquement les assets statiques (CSS/JS/images), en stale-while-
   revalidate ; les URLs versionnées (?v=mtime, cf. versioned_asset()) gèrent
   déjà l'invalidation, pas besoin de bump manuel de version ici.
   Tout le reste (HTML, /recognize, /store) passe toujours par le réseau —
   jamais de page ou de ticket de scan périmé servi depuis le cache. */
'use strict';

var STATIC_CACHE = 'presence-static-v1';
var STATIC_EXT = /\.(css|js|png|jpg|jpeg|svg|webp|woff2?)$/;

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET' || !STATIC_EXT.test(new URL(req.url).pathname)) {
        return;
    }

    event.respondWith(
        caches.open(STATIC_CACHE).then(function (cache) {
            return cache.match(req).then(function (cached) {
                var fetchPromise = fetch(req).then(function (res) {
                    cache.put(req, res.clone());
                    return res;
                }).catch(function () { return cached; });
                return cached || fetchPromise;
            });
        })
    );
});
