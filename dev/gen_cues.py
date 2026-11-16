"""Find the start time of every script line in the Listening MP3s (for the tap-a-line transcript).

  python gen_cues.py

New recordings get exact timings from gen_audio.py (<name>.cues.json next to the MP3).
For older MP3s without that file, the pauses between speakers are detected with ffmpeg:
the N-1 longest pauses of an N-line script are the line breaks. A file is only accepted
when those pauses are clearly longer than every other pause; otherwise it is skipped
(the app then simply shows the transcript without tap-to-play).
"""
import json, os, re, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))


def silences(path):
    out = subprocess.run(["ffmpeg", "-hide_banner", "-i", path, "-af", "silencedetect=noise=-45dB:d=0.25", "-f", "null", "-"],
                         capture_output=True, text=True).stderr
    starts = [float(x) for x in re.findall(r"silence_start: (-?[0-9.]+)", out)]
    ends = [(float(a), float(b)) for a, b in re.findall(r"silence_end: ([0-9.]+) \| silence_duration: ([0-9.]+)", out)]
    dur = re.search(r"Duration: (\d+):(\d+):([0-9.]+)", out)
    total = int(dur.group(1)) * 3600 + int(dur.group(2)) * 60 + float(dur.group(3)) if dur else 0
    return [(s, e, d) for s, (e, d) in zip(starts, ends)], total


def cues_for(path, n):
    sil, total = silences(path)
    lead = [x for x in sil if x[0] < 0.1]
    mid = [x for x in sil if x[0] >= 0.1 and x[1] < total - 0.15]
    if len(mid) < n - 1:
        return None
    by_len = sorted(mid, key=lambda x: -x[2])
    if n > 1 and len(by_len) > n - 1 and by_len[n - 2][2] < 1.2 * by_len[n - 1][2]:
        return None  # line breaks are not clearly longer than ordinary pauses
    breaks = sorted(by_len[:n - 1], key=lambda x: x[0])
    first = max(0.0, lead[0][1] - 0.15) if lead else 0.0
    return [round(first, 2)] + [round(max(0.0, e - 0.15), 2) for _, e, _ in breaks]


if __name__ == "__main__":
    idx = json.load(open(os.path.join(HERE, "index.json")))
    ok = bad = kept = 0
    for t in idx["tests"]:
        test = json.load(open(os.path.join(HERE, t["file"]), encoding="utf-8"))
        for i, part in enumerate(test["listening"]["parts"], 1):
            for d in ("audio_pub", "audio_ms"):
                mp3 = os.path.join(HERE, d, f"{test['id']}-p{i}.mp3")
                side = mp3[:-4] + ".cues.json"
                if not os.path.exists(mp3):
                    continue
                if os.path.exists(side) and os.path.getmtime(side) >= os.path.getmtime(mp3):
                    kept += 1
                    continue
                c = cues_for(mp3, len(part["script"]))
                if c:
                    json.dump(c, open(side, "w")); ok += 1
                else:
                    bad += 1; print("no clear line breaks:", d, os.path.basename(mp3))
    print(f"cues: {ok} detected, {kept} already there, {bad} skipped")
