"""Second opinion on the answer keys: someone answers the test without seeing the key, then the two are compared.

  python dev/blind_check.py export gt7 <folder>     writes <folder>/gt7.questions.json (texts + questions, no answers)
  python dev/blind_check.py grade  gt7 <folder>     reads  <folder>/gt7.answers.json and lists every disagreement

answers file: {"L": {"1": "Okafor", "11": "B", ...}, "R": {"1": "TRUE", ...}, "doubts": {"R8": "why it is unclear"}}
A disagreement is not always a wrong key, but each one must be looked at by hand.
"""
import json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
loose = lambda s: re.sub(r"^(a|an|the)\s+", "", re.sub(r"[£$,]", "", str(s).lower()).strip()).replace(" ", "").replace("-", "").rstrip(".")


def sections(t):
    yield "L", t["listening"]["parts"]
    yield "R", t["reading"]["sections"]


def export(tid, out):
    t = json.load(open(os.path.join(HERE, tid + ".json"), encoding="utf-8")); doc = {"id": tid, "L": [], "R": []}
    for k, secs in sections(t):
        for s in secs:
            text = [f"{w}: {x}" for w, x in s["script"]] if k == "L" else [{"title": p["title"], "paras": p["paras"]} for p in s["passages"]]
            gs = [{**{a: b for a, b in g.items() if a != "items"}, "items": [{a: b for a, b in it.items() if a not in ("answer", "why")} for it in g["items"]]} for g in s["groups"]]
            doc[k].append({"title": s["title"], "text": text, "groups": gs})
    json.dump(doc, open(os.path.join(out, tid + ".questions.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)


def grade(tid, out):
    t = json.load(open(os.path.join(HERE, tid + ".json"), encoding="utf-8"))
    a = json.load(open(os.path.join(out, tid + ".answers.json"), encoding="utf-8")); n = bad = 0
    for k, secs in sections(t):
        for s in secs:
            for g in s["groups"]:
                for it in g["items"]:
                    n += 1; got = str(a.get(k, {}).get(str(it["n"]), "")).strip(); key = it["answer"]
                    ok = any(loose(x) == loose(got) for x in key) if g["type"] == "gap" else got.upper() == str(key).upper()
                    if not ok: bad += 1; print(f"  {k}{it['n']} ({g['type']}): key {key!r}, blind answer {got!r}")
    for q, why in (a.get("doubts") or {}).items(): print(f"  doubt {q}: {why}")
    print(f"{tid}: {n - bad} of {n} agree")


if __name__ == "__main__":
    (export if sys.argv[1] == "export" else grade)(sys.argv[2], sys.argv[3])
