// ============================================================
// Baukalkulation – Service Worker (PWA Offline Support)
// ============================================================
const CACHE_VERSION = 'bk-es-v214';
const PRECACHE_URLS = [
  './',
  './index.html',
  './mobile.html',
  './mobile_light.html',
  './login.html',
  './bedienungsanleitung.html',
  './style.css?v=144',
  './script.min.js?v=215',
  './dist/core.js?v=100',
  './manifest.json',
  './icons/icon-512.png',
  './icons/icon.svg',
  './lib/xlsx.full.min.js',
  './lib/chart.umd.min.js',
  './lib/html2pdf.bundle.min.js',
  './lib/pdfjs/pdf.min.js',
  './lib/pdfjs/pdf.worker.min.js'
];

// ── Redirect-Bereinigung (Safari/WebKit-Fix) ─────────────────
// Safari verweigert JEDE vom Service Worker ausgelieferte Antwort mit
// response.redirected === true ("Response served by service worker has
// redirections"). Tritt auf, wenn der Server eine Navigationsseite
// weiterleitet (z.B. './' -> index.html, Redirect zu login.html, HTTPS/
// Trailing-Slash). Wir bauen solche Antworten als saubere, nicht-
// weitergeleitete Response neu auf, bevor sie gecacht/ausgeliefert werden.
async function cleanRedirect(response) {
  if (!response || !response.redirected) return response;
  const body = await response.clone().blob();
  return new Response(body, {
    status: response.status,
    statusText: response.statusText,
    headers: response.headers
  });
}

// ── Vollständigkeits-Prüfung + Redirect-Bereinigung fürs Cachen ──
// Liest den Body einmalig, baut eine saubere (nicht-weitergeleitete)
// Response neu auf und prüft, ob die Antwort vollständig ist. Trunkierte
// Antworten (z.B. abgebrochener Download im Funkloch → halbe aufmass.js/
// lager.js) haben eine kleinere Bytegröße als der Content-Length-Header und
// dürfen NICHT gecacht werden, sonst liefert der SW dauerhaft eine kaputte,
// syntaktisch ungültige Datei aus.
async function cleanForCache(response) {
  const blob = await response.clone().blob();
  const cl = response.headers.get('Content-Length');
  const complete = !cl || blob.size === Number(cl);
  const clean = new Response(blob, {
    status: response.status,
    statusText: response.statusText,
    headers: response.headers
  });
  return { clean, complete };
}

// ── Install: Pre-cache App-Shell ─────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then(cache =>
      // Einzeln cachen statt addAll (atomar): ein fehlendes/optionales Asset
      // (z.B. ein nicht gebautes dist/-Bundle) darf die komplette SW-Installation
      // nicht verhindern – sonst wird gar nichts gecacht (auch nicht html2pdf).
      // fetch+cleanRedirect statt cache.add(), damit weitergeleitete Antworten
      // (z.B. './') nicht mit redirected=true gespeichert werden (Safari-Fix).
      Promise.all(PRECACHE_URLS.map(url =>
        fetch(url, { redirect: 'follow' })
          .then(resp => { if (resp && resp.ok) return cleanForCache(resp).then(({ clean, complete }) => { if (complete) return cache.put(url, clean); }); })
          .catch(err => console.warn('[SW] Precache übersprungen:', url, err))
      ))
    ).then(() => self.skipWaiting())
  );
});


// ── SKIP_WAITING message handler ─────────────────────────────
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// ── Activate: Alte Caches aufräumen ──────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: Network-first für API, Cache-first für Assets ─────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // API-Aufrufe: Network first, fallback auf gespeicherte Daten
  if (url.pathname.endsWith('api.php')) {
    event.respondWith(
      fetch(event.request.clone())
        .then(response => {
          // Erfolgreiche GET-Antworten cachen (load, backups) – NICHT check/logout
          const action = url.searchParams.get('action');
          if (event.request.method === 'GET' && response.ok && action !== 'check' && action !== 'logout') {
            const rc = response.clone();
            caches.open(CACHE_VERSION).then(cache => cache.put(event.request, rc));
          }
          return response;
        })
        .catch(() => {
          // Offline: Cache-Fallback für GET-Anfragen
          if (event.request.method === 'GET') {
            return caches.match(event.request).then(cached => {
              if (cached) return cached;
              // Spezial: action=check → offline-fähige Antwort
              if (url.searchParams.get('action') === 'check') {
                return new Response(JSON.stringify({
                  loggedIn: true,
                  username: '__offline__',
                  role: 'normal',
                  permissions: {},
                  visibility: 'all',
                  offline: true
                }), { headers: { 'Content-Type': 'application/json' } });
              }
              // action=load → lokale Daten aus Cache
              if (url.searchParams.get('action') === 'load') {
                return new Response(JSON.stringify({
                  data: null,
                  offline: true
                }), { headers: { 'Content-Type': 'application/json' } });
              }
              return new Response(JSON.stringify({ error: 'Offline' }), {
                status: 503,
                headers: { 'Content-Type': 'application/json' }
              });
            });
          }
          // POST offline → 503 (Sync-Queue im Client kümmert sich)
          return new Response(JSON.stringify({ error: 'Offline', queued: true }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
          });
        })
    );
    return;
  }

  // Statische Assets: Cache first, network fallback
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) {
        // Im Hintergrund aktualisieren (stale-while-revalidate)
        fetch(event.request).then(response => {
          if (response.ok) {
            cleanForCache(response).then(({ clean, complete }) => {
              if (complete) caches.open(CACHE_VERSION).then(cache => cache.put(event.request, clean));
            });
          }
        }).catch(() => {});
        return cached;
      }
      return fetch(event.request).then(response => {
        if (response.ok) {
          // Weitergeleitete Antworten bereinigen (Safari-Fix) + Trunkierung prüfen,
          // dann nur vollständige Antworten cachen; immer sauber ausliefern.
          return cleanForCache(response).then(({ clean, complete }) => {
            if (complete) {
              const rc = clean.clone();
              caches.open(CACHE_VERSION).then(cache => cache.put(event.request, rc));
            }
            return clean;
          });
        }
        return response;
      });
    })
  );
});

// ── Background Sync: Wartende Daten senden ───────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'bk-sync') {
    event.waitUntil(doBackgroundSync());
  }
});

async function doBackgroundSync() {
  // Nachricht an Client senden, damit dieser die Sync-Queue abarbeitet
  const clients = await self.clients.matchAll({ type: 'window' });
  clients.forEach(client => {
    client.postMessage({ type: 'BK_SYNC_NOW' });
  });
}

