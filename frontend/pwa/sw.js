const CACHE_NAME = 'pad2832-pwa-v1.6';

const ASSETS_TO_CACHE = [
  './index.html',
  './manifest.json',
  './css/pwa-style.css',
  './js/qrcode.min.js',
  './js/api.js',
  './js/auth.js',
  './js/router.js',
  './js/views/ml.js',
  './js/views/coordinador.js',
  './js/views/inscripcion.js',
  './js/views/consulta.js',
  './js/views/mis_inscritos.js',
  './js/app.js',
  './img/logo.png',
  './img/icon-192.png',
  './img/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = event.request.url;

  // 1. Bypass total de red para APIs backend y endpoints PHP (Siempre datos frescos en vivo)
  if (url.includes('/backend/api/') || url.includes('.php') || event.request.method !== 'GET') {
    event.respondWith(
      fetch(event.request).catch(() => {
        return new Response(JSON.stringify({
          exito: false,
          offline: true,
          mensaje: 'Sin conexión a internet. La operación requiere red.'
        }), {
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          status: 503
        });
      })
    );
    return;
  }

  // 2. Estrategia Stale-While-Revalidate para recursos estáticos de la PWA
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      const fetchPromise = fetch(event.request).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, networkResponse.clone());
          });
        }
        return networkResponse;
      }).catch(() => cachedResponse);

      return cachedResponse || fetchPromise;
    })
  );
});
