// MyPOS Lite - Service Worker PWA Offline
const CACHE_NAME = 'myposlite-app-v2'
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/manifest.webmanifest',
  '/icons/icon.svg',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
]

// 1. Instalación: Pre-cacheados de archivos esenciales (App Shell)
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('[SW] Aviso al pre-cachear:', err)
      })
    })
  )
  self.skipWaiting()
})

// 2. Activación: Limpieza de cachés antiguas
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      )
    })
  )
  self.clients.claim()
})

// 3. Estrategias de Fetch según el tipo de recurso
self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Ignorar extensiones de navegador y peticiones no HTTP/HTTPS
  if (!url.protocol.startsWith('http')) return

  // A. Peticiones a la API (/api/...)
  if (url.pathname.startsWith('/api/')) {
    // Para APIs siempre ir a la red primero
    event.respondWith(
      fetch(request).catch(() => {
        // Si no hay red, responder JSON offline informativo
        return new Response(
          JSON.stringify({
            offline: true,
            error: 'Sin conexión a internet. La operación se procesará en almacenamiento local.',
          }),
          {
            status: 503,
            headers: {
              'Content-Type': 'application/json',
              'X-MyPOSLite-Offline': '1',
            },
          }
        )
      })
    )
    return
  }

  // B. Peticiones de navegación (cuando el usuario entra o refresca cualquier URL)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Si hubo éxito, actualizar el index.html en caché
          const clone = response.clone()
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone))
          return response
        })
        .catch(async () => {
          // Si estamos offline, entregar el index.html pre-cacheado (SPA Offline)
          const cached = await caches.match('/index.html')
          if (cached) return cached
          return caches.match('/')
        })
    )
    return
  }

  // C. Assets estáticos versionados de Vite (/assets/*) -> Cache First
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached
        return fetch(request).then((response) => {
          if (response && response.status === 200) {
            const clone = response.clone()
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone))
          }
          return response
        })
      })
    )
    return
  }

  // D. Resto de recursos -> Network First con Cache Fallback
  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response && response.status === 200) {
          const clone = response.clone()
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone))
        }
        return response
      })
      .catch(() => caches.match(request))
  )
})

// Escucha de mensajes desde la aplicación (e.g. actualizar inmediatamente)
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting()
  }
})
