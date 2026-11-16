"""Change fields of one question:  python dev/fix_q.py gt9 L2 '{"answer": ["21 June", "21"]}'"""
import json, os, sys
p = os.path.join(os.path.dirname(os.path.abspath(__file__)), sys.argv[1] + ".json"); t = json.load(open(p, encoding="utf-8")); ref = sys.argv[2]; n = 0
for s in (t["listening"]["parts"] if ref[0] == "L" else t["reading"]["sections"]):
    for g in s["groups"]:
        for it in g["items"]:
            if it["n"] == int(ref[1:]): it.update(json.loads(sys.argv[3])); n += 1
assert n == 1, n
json.dump(t, open(p, "w", encoding="utf-8"), ensure_ascii=False, indent=1); print("fixed", sys.argv[1], ref)
