const CACHE_NAME = "reporte-terreno-v12";
const ASSETS = [
  "/",
  "/index.html",
  "/manifest.json",
  "/icon-192.png",
  "/icon-512.png"
];

self.addEventListener("install", event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      for (const url of ASSETS) {
        try {
          const resp = await fetch(url, { cache: "no-cache" });
          if (resp && resp.ok && resp.status === 200) {
            await cache.put(url, resp);
          }
        } catch (e) {}
      }
    })
  );
  self.skipWaiting();
});

self.addEventListener("activate", event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

function fetchWithTimeout(req, ms) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error("timeout")), ms);
    fetch(req, { cache: "no-cache" })
      .then(resp => {
        clearTimeout(timer);
        resolve(resp);
      })
      .catch(err => {
        clearTimeout(timer);
        reject(err);
      });
  });
}

self.addEventListener("fetch", event => {
  const req = event.request;
  if (req.method !== "GET" || req.url.includes("api.php") || req.url.includes("/api/")) {
    return;
  }

  event.respondWith(
    fetchWithTimeout(req, 3500)
      .then(networkResp => {
        if (networkResp && networkResp.ok && networkResp.status === 200) {
          const copy = networkResp.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy)).catch(() => {});
          return networkResp;
        }
        throw new Error("bad-status");
      })
      .catch(async () => {
        const cached = await caches.match(req, { ignoreSearch: true });
        if (cached) return cached;
        const fallbackIndex = await caches.match("/index.html", { ignoreSearch: true });
        if (fallbackIndex) return fallbackIndex;
        return caches.match("/", { ignoreSearch: true });
      })
  );
});
