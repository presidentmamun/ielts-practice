"""Transcribe every Listening MP3 with Whisper and compare it with the script.
Reports (1) answers that cannot be heard in the audio, (2) word-level differences.

  ttsenv\\Scripts\\python.exe check_audio.py [audio_dir] [test ids...]
"""
import json, os, re, sys, difflib
from faster_whisper import WhisperModel

HERE = os.path.dirname(os.path.abspath(__file__))
args = sys.argv[1:]
adir = args.pop(0) if args and not args[0].startswith("gt") and not args[0].startswith("ac") else "audio"
ids = args or [t["id"] for t in json.load(open(os.path.join(HERE, "index.json")))["tests"]]
m = WhisperModel("small.en", device="cpu", compute_type="int8")

NUM = {"zero": "0", "oh": "0", "one": "1", "two": "2", "three": "3", "four": "4", "five": "5", "six": "6", "seven": "7", "eight": "8", "nine": "9", "ten": "10"}


def words(s):
    s = s.lower().replace("£", " ").replace("%", " percent")
    s = re.sub(r"(?<=\d)[,](?=\d{3})", "", s)
    ws = re.findall(r"[a-z]+|\d+", s)
    return [NUM.get(w, w) for w in ws]


report = []
for tid in ids:
    t = json.load(open(os.path.join(HERE, tid + ".json"), encoding="utf-8"))
    for i, part in enumerate(t["listening"]["parts"], 1):
        f = os.path.join(HERE, adir, f"{tid}-p{i}.mp3")
        if not os.path.exists(f):
            continue
        segs, _ = m.transcribe(f, beam_size=1, language="en")
        heard = " ".join(s.text for s in segs)
        H, S = words(heard), words(" ".join(x[1] for x in part["script"]))
        hs = " ".join(H)
        missing = []
        for g in part["groups"]:
            for it in g["items"]:
                if g["type"] != "gap":
                    continue
                ok = any(" ".join(words(a)) and " ".join(words(a)) in hs for a in it["answer"])
                if not ok:
                    missing.append(f"Q{it['n']}={it['answer'][0]}")
        diffs = []
        for op, a1, a2, b1, b2 in difflib.SequenceMatcher(None, S, H, autojunk=False).get_opcodes():
            if op != "equal":
                diffs.append(f"{op}: script[{' '.join(S[max(0,a1-2):a2+2])}] heard[{' '.join(H[max(0,b1-2):b2+2])}]")
        line = f"{tid} p{i}: {len(diffs)} diffs; answers not heard: {missing or 'none'}"
        print(line, flush=True)
        report.append(line)
        report += ["   " + d for d in diffs[:40]]
open(os.path.join(HERE, f"audio_check_{adir}.txt"), "w", encoding="utf-8").write("\n".join(report) + "\n")
print("done")
