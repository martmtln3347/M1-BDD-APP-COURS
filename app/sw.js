/**
 * Service worker : garde en cache les images du jeu (CDN acnhcdn.com) une
 * fois vues, pour que le site reste illustre hors ligne. Il ne touche a rien
 * d'autre : pages, scripts et API passent toujours par le reseau, donc
 * aucune donnee de compte n'est jamais mise en cache.
 */
const CACHE = 'nook-images-v1';
const CDN = 'acnhcdn.com';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (evenement) => {
  evenement.waitUntil(
    caches.keys()
      .then((noms) => Promise.all(noms.filter((n) => n !== CACHE).map((n) => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (evenement) => {
  const requete = evenement.request;
  if (requete.method !== 'GET') return;

  const url = new URL(requete.url);
  if (url.protocol !== 'https:' || url.hostname !== CDN) return;

  evenement.respondWith(
    caches.open(CACHE).then((cache) =>
      cache.match(requete).then((enCache) => {
        if (enCache) return enCache;
        return fetch(requete).then((reponse) => {
          // Les images du CDN arrivent en reponse opaque (pas de CORS) : on les garde telles quelles.
          if (reponse.ok || reponse.type === 'opaque') cache.put(requete, reponse.clone());
          return reponse;
        });
      })
    )
  );
});
