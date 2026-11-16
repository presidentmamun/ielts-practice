// Offline support. The app and the tests are cached when the app is installed.
// A recording is downloaded and cached in full the first time it is played, then served from the cache
// (browsers ask for audio in byte ranges, so cached recordings are sliced here to answer those requests).
const CACHE = "ielts-desk-e5d4b293f2";
const SHELL = ["./", "index.html", "manifest.webmanifest", "icon-192.png", "icon-512.png", "premium/meta.json", "index.json", "gt1.json", "gt2.json", "gt3.json", "gt4.json", "gt5.json", "gt6.json", "gt7.json", "gt8.json", "ac1.json", "ac2.json"];
self.addEventListener("install", e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener("activate", e => e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));
async function ranged(req, res) {
  const m = /bytes=(\d+)-(\d*)/.exec(req.headers.get("range") || "");
  if (!m) return res;
  const buf = await res.arrayBuffer(), start = +m[1], end = m[2] ? Math.min(+m[2], buf.byteLength - 1) : buf.byteLength - 1;
  return new Response(buf.slice(start, end + 1), {status: 206, statusText: "Partial Content", headers: {
    "Content-Type": res.headers.get("Content-Type") || "audio/mpeg", "Content-Range": `bytes ${start}-${end}/${buf.byteLength}`,
    "Content-Length": String(end - start + 1), "Accept-Ranges": "bytes"}});
}
async function media(req, url) {
  try {
    const c = await caches.open(CACHE);
    let hit = await c.match(url);
    if (!hit) {
      const res = await fetch(url);
      if (res.status !== 200) return fetch(req);
      await c.put(url, res.clone()); hit = res;
    }
    return req.headers.has("range") ? ranged(req, hit) : hit;
  } catch (err) { return fetch(req) }
}
self.addEventListener("fetch", e => {
  const u = new URL(e.request.url);
  if (e.request.method !== "GET" || u.origin !== location.origin) return;
  if (/\.(mp3|bin)$/.test(u.pathname)) return e.respondWith(media(e.request, u.href));
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
    if (res.ok && res.status === 200) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)) }
    return res;
  })));
});
