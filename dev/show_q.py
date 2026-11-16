"""Print questions with their instruction:  python dev/show_q.py gt9 L2 R37"""
import json, os, sys
t = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), sys.argv[1] + ".json"), encoding="utf-8"))
for ref in sys.argv[2:]:
    for s in (t["listening"]["parts"] if ref[0] == "L" else t["reading"]["sections"]):
        for g in s["groups"]:
            for it in g["items"]:
                if it["n"] == int(ref[1:]): print(ref, "|", g["instr"][:90], "\n   ", json.dumps(it, ensure_ascii=False))
