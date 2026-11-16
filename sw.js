// Offline support: app shell and tests are cached on install; audio is cached the first time it plays.
const CACHE = "ielts-desk-67852173";
const SHELL = ["./", "index.html", "manifest.webmanifest", "icon-192.png", "index.json", "gt1.json", "gt2.json", "gt3.json", "gt4.json", "gt5.json", "gt6.json"];
self.addEventListener("install", e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener("activate", e => e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener("fetch", e => {
  const u = new URL(e.request.url);
  if (e.request.method !== "GET" || u.origin !== location.origin) return;
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
    if (res.ok && res.status === 200) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)) }
    return res;
  })));
});
