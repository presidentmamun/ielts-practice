"""Build the public site (GitHub Pages) into the folder above this one.

  python dev/build.py

Everything the site is made from lives here in dev/:
  app.html            the whole app (styles + script); edit this, never ../index.html
  index.json, gt*.json  the tests (source, with Listening scripts)
  audio_pub/          free Listening MP3s (open-licence Kokoro voices)        -> ../audio/
  audio_ms/           natural-voice MP3s for members; never published as-is   -> ../premium/*.bin (encrypted)
  premium-key.txt     the members' access key; never published
  gen_audio.py        makes the MP3s    gen_cues.py  finds line timings for the tap-a-line transcript
"""
import base64, hashlib, json, os, re, secrets, shutil

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.dirname(HERE)
os.makedirs(os.path.join(SITE, "audio"), exist_ok=True)
for _f in os.listdir(os.path.join(SITE, "audio")):
    os.remove(os.path.join(SITE, "audio", _f))  # drop stale audio; current files are copied below

body = open(os.path.join(HERE, "app.html"), encoding="utf-8").read()
idx = json.load(open(os.path.join(HERE, "index.json")))
files = ["index.json"] + [t["file"] for t in idx["tests"]]

# ---- premium (natural voice) audio: AES-GCM encrypted, unlocked in the browser with a passphrase ----
KEYFILE = os.path.join(HERE, "premium-key.txt")  # stays in dev/ (git-ignored), never copied to the site
if not os.path.exists(KEYFILE):
    open(KEYFILE, "w").write("-".join(secrets.token_hex(2) for _ in range(4)) + "\n")
PASS = open(KEYFILE).read().strip()
PDIR = os.path.join(SITE, "premium"); os.makedirs(PDIR, exist_ok=True)
meta_path = os.path.join(PDIR, "meta.json")
meta = json.load(open(meta_path)) if os.path.exists(meta_path) else {"salt": base64.b64encode(secrets.token_bytes(16)).decode(), "iter": 200000}
key = PBKDF2HMAC(algorithm=hashes.SHA256(), length=32, salt=base64.b64decode(meta["salt"]), iterations=meta["iter"]).derive(PASS.encode())
aes = AESGCM(key)


def enc(data):
    iv = secrets.token_bytes(12); return iv + aes.encrypt(iv, data, None)


def cues(mp3, n):
    """Line start times saved next to an MP3, if they match the script."""
    side = mp3[:-4] + ".cues.json"
    if os.path.exists(side) and os.path.getmtime(side) >= os.path.getmtime(mp3):
        c = json.load(open(side))
        if len(c) == n:
            return c
    return None


rekey = False
try:  # keep the old check value when the key still opens it, so the file does not change on every build
    aes.decrypt(base64.b64decode(meta["check"])[:12], base64.b64decode(meta["check"])[12:], None)
except Exception:
    meta["check"] = base64.b64encode(enc(b"ielts-desk-ok")).decode()
    rekey = True  # the key changed: every recording must be encrypted again with the new one
json.dump(meta, open(meta_path, "w"))

n_audio = n_prem = 0
for f in files[1:]:
    t = json.load(open(os.path.join(HERE, f), encoding="utf-8"))
    for i, part in enumerate(t["listening"]["parts"], 1):
        name = f"{t['id']}-p{i}"
        for k in ("audio", "premium", "cues", "pcues"):
            part.pop(k, None)
        src = os.path.join(HERE, "audio_pub", name + ".mp3")
        if os.path.exists(src):
            shutil.copyfile(src, os.path.join(SITE, "audio", name + ".mp3"))
            part["audio"] = f"audio/{name}.mp3"; n_audio += 1
            c = cues(src, len(part["script"]))
            if c:
                part["cues"] = c
        ms = os.path.join(HERE, "audio_ms", name + ".mp3")
        dst = os.path.join(PDIR, name + ".bin")
        if os.path.exists(ms):
            if rekey or not os.path.exists(dst) or os.path.getmtime(dst) < os.path.getmtime(ms):
                open(dst, "wb").write(enc(open(ms, "rb").read()))
            part["premium"] = f"premium/{name}.bin"; n_prem += 1
            c = cues(ms, len(part["script"]))
            if c:
                part["pcues"] = c
        elif os.path.exists(dst):
            os.remove(dst)
    json.dump(t, open(os.path.join(SITE, f), "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
shutil.copyfile(os.path.join(HERE, "index.json"), os.path.join(SITE, "index.json"))

# ---- index.html: title, meta and font links belong in <head> ----
head_bits = re.findall(r"^<(?:title>.*?</title|meta [^>]*|link [^>]*)>\s*$", body, flags=re.M)
for b in head_bits:
    body = body.replace(b + "\n", "", 1)
head_bits = [b for b in head_bits if 'name="claude:' not in b]
head = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="description" content="Free IELTS practice in the computer-delivered format: Listening, Reading and Writing, practice at your own pace or timed mock tests, with explanations and band estimates.">
<link rel="canonical" href="https://ielts.iwate-bd.org/">
<meta property="og:title" content="IELTS Practice Desk">
<meta property="og:url" content="https://ielts.iwate-bd.org/">
<meta property="og:image" content="https://ielts.iwate-bd.org/icon-512.png">
<meta name="theme-color" content="#0b7a80">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon-192.png">
<link rel="apple-touch-icon" href="icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
""" + "\n".join(head_bits) + """
<style>:root{padding-top:env(safe-area-inset-top,0px)}img{max-width:100%}[hidden]{display:none!important}</style>
</head>
<body>
"""
sw_reg = """<script>if ("serviceWorker" in navigator) addEventListener("load", () => navigator.serviceWorker.register("sw.js").catch(() => {}));</script>
"""
open(os.path.join(SITE, "index.html"), "w", encoding="utf-8", newline="\n").write(head + body.lstrip() + "\n" + sw_reg + "</body>\n</html>\n")

# ---- service worker: app + tests cached on install; each recording cached in full the first time it is played ----
h = hashlib.sha256(body.encode("utf-8"))
for f in files:
    h.update(open(os.path.join(SITE, f), "rb").read())
version = h.hexdigest()[:10]
shell = ["./", "index.html", "manifest.webmanifest", "icon-192.png", "icon-512.png", "premium/meta.json"] + files
open(os.path.join(SITE, "sw.js"), "w", encoding="utf-8", newline="\n").write("""// Offline support. The app and the tests are cached when the app is installed.
// A recording is downloaded and cached in full the first time it is played, then served from the cache
// (browsers ask for audio in byte ranges, so cached recordings are sliced here to answer those requests).
const CACHE = "ielts-desk-%s";
const SHELL = %s;
// cache: "reload" skips the browser's own 10-minute cache, so a new version never stores the previous page
self.addEventListener("install", e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL.map(u => new Request(u, {cache: "reload"})))).then(() => self.skipWaiting())));
self.addEventListener("activate", e => e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));
async function ranged(req, res) {
  const m = /bytes=(\\d+)-(\\d*)/.exec(req.headers.get("range") || "");
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
  if (/\\.(mp3|bin)$/.test(u.pathname)) return e.respondWith(media(e.request, u.href));
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
    if (res.ok && res.status === 200) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)) }
    return res;
  })));
});
""" % (version, json.dumps(shell)))

json.dump({"name": "IELTS Practice Desk", "short_name": "IELTS Desk", "start_url": "./", "scope": "./", "display": "standalone",
           "background_color": "#f5f6f8", "theme_color": "#0b7a80",
           "icons": [{"src": "icon-192.png", "sizes": "192x192", "type": "image/png"},
                     {"src": "icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable"}]},
          open(os.path.join(SITE, "manifest.webmanifest"), "w"), indent=1)

from PIL import Image, ImageDraw, ImageFont
for size in (192, 512):
    im = Image.new("RGB", (size, size), "#0b7a80")
    d = ImageDraw.Draw(im)
    try:
        font = ImageFont.truetype("arialbd.ttf", int(size * 0.34))
    except OSError:
        font = ImageFont.load_default()
    d.text((size / 2, size * 0.46), "7.5", fill="#ffffff", font=font, anchor="mm")
    d.rounded_rectangle([size * 0.2, size * 0.68, size * 0.8, size * 0.74], radius=size * 0.03, fill="#ffe68a")
    im.save(os.path.join(SITE, f"icon-{size}.png"))

open(os.path.join(SITE, "README.md"), "w", encoding="utf-8", newline="\n").write("""# IELTS Practice Desk

A free practice app for IELTS in the computer-delivered format.

- **Practice**: no clock. One part at a time, or one question type at a time; answers are checked as you go,
  the recording can be paused, rewound and slowed down, and you can tap any line of the transcript to hear it again.
- **Mock test**: exam conditions. Countdown, the recording plays once, band estimate at the end.
- Works offline once installed ("Add to Home Screen").

All questions, passages and recordings are original. The free Listening audio is generated with the open-source
Kokoro-82M model (Apache-2.0). This is an independent tool and is not affiliated with or endorsed by IELTS,
the British Council, IDP or Cambridge University Press & Assessment.

## Working on it

The site in this folder is generated. Edit the sources in `dev/` (`dev/app.html` is the whole app,
`dev/gt*.json` are the tests) and run `python dev/build.py`.
""")
open(os.path.join(SITE, ".gitignore"), "w", newline="\n").write("""CLAUDE.md
.claude/
*claude*.md
MEMORY.md
memory/
# dev/: keep sources and scripts, never the access key, the unencrypted members' audio or bulky local files
dev/premium-key.txt
dev/audio_ms/
dev/audio_pub/
dev/audio/
dev/ttsenv/
dev/_backup*/
dev/__pycache__/
dev/*.log
dev/*.txt

# local Firebase tests, rules and test account (never publish)
dev/firebase/
""")
open(os.path.join(SITE, ".nojekyll"), "w").write("")
print("built", SITE, "| tests:", len(files) - 1, "| free recordings:", n_audio, "| members' recordings:", n_prem, "| version:", version)
