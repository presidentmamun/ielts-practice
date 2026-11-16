"""Render Listening scripts to MP3 with the free, local Kokoro TTS model (no API, no tokens).

  ttsenv\\Scripts\\python.exe gen_audio.py gt1 gt2 ...   (default: every test in index.json)

Writes audio/<test>-p<part>.mp3 and adds "audio": "<path>" to each part in the test JSON.
Existing MP3s are skipped, so it is safe to re-run after adding tests.
"""
import json, os, re, sys
import numpy as np
import lameenc
from kokoro import KPipeline

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "audio")
SR = 24000
# speaker voice label in the test JSON -> (Kokoro language code, Kokoro voice)
VOICES = {
    "buttery": ("b", "bm_george"),   # British male
    "airy":    ("b", "bf_emma"),     # British female
    "glassy":  ("a", "af_heart"),    # American female
    "rounded": ("b", "bf_isabella"), # British female 2
    "deep":    ("a", "am_michael"),  # American male
    "bright":  ("b", "bm_lewis"),    # British male 2
    # Australian / New Zealand: Microsoft neural voices via edge-tts (Kokoro has no Australian voice)
    "aus_f":   ("edge", "en-AU-NatashaNeural"),
    "aus_m":   ("edge", "en-AU-WilliamMultilingualNeural"),
    "nz_f":    ("edge", "en-NZ-MollyNeural"),
    "nz_m":    ("edge", "en-NZ-MitchellNeural"),
}
PIPES = {}


def speak_edge(text, voice):
    import asyncio, tempfile, soundfile as sf, edge_tts
    tmp = tempfile.NamedTemporaryFile(suffix=".mp3", delete=False); tmp.close()
    for attempt in range(5):
        try:
            asyncio.run(edge_tts.Communicate(text, voice).save(tmp.name))
            break
        except Exception:
            if attempt == 4:
                raise
            import time; time.sleep(3 * (attempt + 1))
    wave, sr = sf.read(tmp.name, dtype="float32")
    os.remove(tmp.name)
    if wave.ndim > 1:
        wave = wave.mean(axis=1)
    if sr != SR:  # simple linear resample to 24 kHz
        x = np.linspace(0, len(wave) - 1, int(len(wave) * SR / sr))
        wave = np.interp(x, np.arange(len(wave)), wave).astype(np.float32)
    return wave


def pipe(lang):
    if lang not in PIPES:
        PIPES[lang] = KPipeline(lang_code=lang, repo_id="hexgrad/Kokoro-82M")
    return PIPES[lang]


def speak(text, voice):
    text = tts_text(text)
    if MS:
        return speak_edge(text, MS_MAP.get(voice, "en-GB-RyanNeural"))
    lang, v = VOICES.get(voice, VOICES["buttery"])
    if lang == "edge":
        return speak_edge(text, v)
    chunks = [a.numpy() if hasattr(a, "numpy") else np.asarray(a) for _, _, a in pipe(lang)(text, voice=v, speed=1.0)]
    return np.concatenate(chunks) if chunks else np.zeros(0, dtype=np.float32)


def to_mp3(wave, path):
    pcm = (np.clip(wave, -1, 1) * 32767).astype(np.int16).tobytes()
    enc = lameenc.Encoder()
    enc.set_bit_rate(48); enc.set_in_sample_rate(SR); enc.set_channels(1); enc.set_quality(2)
    with open(path, "wb") as f:
        f.write(enc.encode(pcm) + enc.flush())


# Public build: only openly licensed Kokoro voices (Apache-2.0); Microsoft voices replaced
PUBLIC_MAP = {"aus_f": "pub_f1", "aus_m": "pub_m1", "nz_f": "pub_f2", "nz_m": "pub_m2"}
VOICES.update({"pub_f1": ("b", "bf_lily"), "pub_m1": ("b", "bm_daniel"),
               "pub_f2": ("b", "bf_alice"), "pub_m2": ("b", "bm_fable")})
PUBLIC = "--public" in sys.argv
# Private build: Microsoft neural voices for every speaker (most natural; personal use only)
MS = "--ms" in sys.argv
MS_MAP = {"buttery": "en-GB-RyanNeural", "airy": "en-GB-SoniaNeural", "glassy": "en-US-AvaNeural",
          "rounded": "en-GB-LibbyNeural", "deep": "en-US-AndrewNeural", "bright": "en-GB-ThomasNeural",
          "aus_f": "en-AU-NatashaNeural", "aus_m": "en-AU-WilliamMultilingualNeural",
          "nz_f": "en-NZ-MollyNeural", "nz_m": "en-NZ-MitchellNeural"}
DIGIT = {"0": "oh", "1": "one", "2": "two", "3": "three", "4": "four", "5": "five", "6": "six", "7": "seven", "8": "eight", "9": "nine"}


def tts_text(s):
    """Make numbers, codes and spellings come out the way a British speaker would say them."""
    # postcodes like TN8 4RD -> "T N 8, 4 R D"
    s = re.sub(r"\b([A-Z]{1,2})(\d{1,2})\s?(\d)([A-Z]{2})\b",
               lambda m: " ".join(m.group(1)) + " " + m.group(2) + ", " + m.group(3) + " " + " ".join(m.group(4)), s)
    # phone numbers / long digit groups (5+ digits, or starting with 0): read digit by digit, 0 = "oh"
    def digits(m):
        return ", ".join(" ".join(DIGIT[c] for c in grp) for grp in m.group(0).split())
    s = re.sub(r"\b0\d{3,}(?:\s\d{3,})*\b|\b\d{5,}(?:\s\d{3,})*\b", digits, s)
    # spelled names H-A-R-P-E-R -> "H. A. R. P. E. R."
    s = re.sub(r"\b[A-Z](?:-[A-Z]){2,}\b", lambda m: ". ".join(m.group(0).split("-")) + ".", s)
    # clock times 6.45 -> 6 45
    s = re.sub(r"\b(\d{1,2})\.(\d{2})\b(?!\s*(?:pounds|euros|%))", r"\1 \2", s)
    s = s.replace("Mbps", "megabits per second").replace("km/h", "kilometres per hour")
    s = re.sub(r"\bHmm\b", "Mm", s)
    # numbers before a unit word: Kokoro sometimes drops the unit after digits, so spell the number out
    s = re.sub(r"\b(\d{1,4})(?=\s+(?:pounds?|euros?|pence|per cent|percent|minutes?|hours?|days?|weeks?|months?|years?|kilometres?|metres?|degrees?|people|items?|tonnes?)\b)",
               lambda m: num_words(int(m.group(1))), s)
    return s


ONES = "zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen".split()
TENS = "_ _ twenty thirty forty fifty sixty seventy eighty ninety".split()


def num_words(n):
    if n < 20:
        return ONES[n]
    if n < 100:
        return TENS[n // 10] + ("-" + ONES[n % 10] if n % 10 else "")
    if n < 1000:
        return ONES[n // 100] + " hundred" + (" and " + num_words(n % 100) if n % 100 else "")
    return num_words(n // 1000) + " thousand" + ((" and " if n % 1000 < 100 else " ") + num_words(n % 1000) if n % 1000 else "")


def render(test_id):
    jp = os.path.join(HERE, test_id + ".json")
    t = json.load(open(jp, encoding="utf-8"))
    for d in ("audio", "audio_pub", "audio_ms"):
        os.makedirs(os.path.join(HERE, d), exist_ok=True)
    for i, part in enumerate(t["listening"]["parts"], 1):
        rel = f"{'audio_ms' if MS else 'audio'}/{test_id}-p{i}.mp3"
        dst = os.path.join(HERE, rel)
        uses_edge = any(VOICES.get(v, ("", ""))[0] == "edge" for v in part["speakers"].values())
        if PUBLIC:
            pub = os.path.join(HERE, "audio_pub", f"{test_id}-p{i}.mp3")
            if os.path.exists(pub):
                continue
            if not uses_edge and os.path.exists(dst):
                import shutil; shutil.copyfile(dst, pub); continue
            dst = pub
        if not os.path.exists(dst):
            gap = np.zeros(int(SR * 0.45), dtype=np.float32)
            pieces = [np.zeros(int(SR * 0.6), dtype=np.float32)]
            cues, pos = [], len(pieces[0])
            for who, text in part["script"]:
                v = part["speakers"].get(who, "buttery")
                w = speak(text, PUBLIC_MAP.get(v, v) if PUBLIC else v)
                cues.append(round(pos / SR, 2)); pos += len(w) + len(gap)
                pieces += [w, gap]
            wave = np.concatenate(pieces)
            to_mp3(wave, dst)
            json.dump(cues, open(dst[:-4] + ".cues.json", "w"))  # start time of every script line
            print(f"{rel}: {len(wave) / SR / 60:.1f} min, {os.path.getsize(dst) / 1e6:.2f} MB", flush=True)
        part["audio"] = rel
    if not PUBLIC:
        json.dump(t, open(jp, "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    ids = [a for a in sys.argv[1:] if not a.startswith("--")] or [x["id"] for x in json.load(open(os.path.join(HERE, "index.json")))["tests"]]
    for tid in ids:
        render(tid)
    print("done", ids)
