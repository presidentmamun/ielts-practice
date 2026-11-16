"""Check test files before they are published. No AI involved: plain rules.

  python dev/check_tests.py            every test in dev/index.json plus any dev/gt*.json / dev/ac*.json not yet listed
  python dev/check_tests.py gt7 ac1    only these

ERROR  = the test must not be published (the app would break, or an answer cannot be right)
warn   = worth a look (length outside the usual range, etc.)
Exit code 1 when any test has an error.
"""
import glob, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
VOICES = {"buttery", "airy", "glassy", "rounded", "deep", "bright", "aus_f", "aus_m", "nz_f", "nz_m"}
TYPES = {"gap", "mcq", "tfng", "ynng", "match"}


def norm(s):
    s = str(s).lower().replace("’", "'")
    s = re.sub(r"[£$,]", "", s)
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9' .:/%-]", " ", s)).strip()


def loose(s):
    return re.sub(r"[\s.-]", "", norm(s))


def words(s):
    return len(re.findall(r"\S+", s))


def check(test, tid):
    E, W = [], []
    mod = test.get("module")
    if test.get("id") != tid: E.append(f"id is {test.get('id')!r}, file says {tid!r}")
    if mod not in ("GT", "AC"): E.append(f"module {mod!r}")
    if not test.get("title"): E.append("no title")

    def groups(where, secs, text_of):
        nums = []
        for si, sec in enumerate(secs):
            text = loose(text_of(sec))
            for g in sec.get("groups", []):
                t = g.get("type")
                if t not in TYPES: E.append(f"{where}: unknown question type {t!r}"); continue
                if not g.get("instr"): E.append(f"{where}: a {t} group has no instructions")
                if t == "match" and not g.get("options"): E.append(f"{where}: matching group without options")
                for it in g.get("items", []):
                    n = it.get("n"); nums.append(n); tag = f"{where} Q{n}"
                    if not it.get("why"): E.append(f"{tag}: no explanation")
                    a = it.get("answer")
                    if t == "gap":
                        if not isinstance(a, list) or not a or not all(isinstance(x, str) and x.strip() for x in a): E.append(f"{tag}: gap answer must be a list of strings"); continue
                        if "before" not in it or "after" not in it: E.append(f"{tag}: gap needs 'before' and 'after'")
                        if max(words(x) for x in a) > 3: W.append(f"{tag}: answer longer than three words: {a}")
                        if not any(loose(x) in text for x in a):
                            if where == "Listening" and any(re.search(r"\d", x) for x in a):  # numbers are often written the way they are spoken
                                W.append(f"{tag}: number {a} is not written like this in the script; check that it is said")
                            else:
                                E.append(f"{tag}: answer {a} is not in the {'recording' if where == 'Listening' else 'passage'}")
                    elif t == "mcq":
                        o = it.get("options") or []
                        if not it.get("q") or not 3 <= len(o) <= 5: E.append(f"{tag}: needs a question and 3-5 options")
                        if not isinstance(a, str) or a not in "ABCDE"[:len(o)] or len(a) != 1: E.append(f"{tag}: answer {a!r} is not one of the options")
                    elif t in ("tfng", "ynng"):
                        ok = ("TRUE", "FALSE", "NOT GIVEN") if t == "tfng" else ("YES", "NO", "NOT GIVEN")
                        if a not in ok or not it.get("q"): E.append(f"{tag}: answer {a!r} must be one of {ok}")
                    elif t == "match":
                        if a not in [o.get("k") for o in g.get("options", [])] or not it.get("q"): E.append(f"{tag}: answer {a!r} is not one of the options")
        if nums != list(range(1, 41)): E.append(f"{where}: questions are not numbered 1-40 in order ({len(nums)} found)")

    # ---- listening
    parts = (test.get("listening") or {}).get("parts") or []
    if len(parts) != 4: E.append(f"Listening has {len(parts)} parts, needs 4")
    for i, p in enumerate(parts, 1):
        for k in ("title", "context", "range", "speakers", "script", "groups"):
            if not p.get(k): E.append(f"Listening part {i}: no {k}")
        sp = p.get("speakers") or {}
        for who, v in sp.items():
            if v not in VOICES: E.append(f"Listening part {i}: voice {v!r} for {who} does not exist")
        for line in p.get("script") or []:
            if not (isinstance(line, list) and len(line) == 2 and line[0] in sp and str(line[1]).strip()): E.append(f"Listening part {i}: bad script line {str(line)[:50]!r}"); break
        if len(sp) != (2 if i in (1, 3) else 1) and not (i == 3 and len(sp) == 3) and not (i == 1 and len(sp) == 2): W.append(f"Listening part {i}: {len(sp)} speakers (usual: parts 1 and 3 conversations, 2 and 4 one speaker)")
        n = sum(words(l[1]) for l in p.get("script") or [] if isinstance(l, list) and len(l) == 2)
        if not 560 <= n <= 850: W.append(f"Listening part {i}: script has {n} words (real exam length: 600-800)")
        if sum(len(g.get("items", [])) for g in p.get("groups") or []) != 10: E.append(f"Listening part {i}: needs exactly 10 questions")
    groups("Listening", parts, lambda p: " ".join(str(l[1]) for l in p.get("script") or [] if isinstance(l, list) and len(l) == 2))

    # ---- reading
    secs = (test.get("reading") or {}).get("sections") or []
    if len(secs) != 3: E.append(f"Reading has {len(secs)} sections, needs 3")
    total = 0
    for i, s in enumerate(secs, 1):
        if not s.get("title") or not s.get("range") or not s.get("passages") or not s.get("groups"): E.append(f"Reading section {i}: missing title, range, passages or groups")
        n = sum(words(x) for p in s.get("passages") or [] for x in p.get("paras") or []); total += n
        for p in s.get("passages") or []:
            if not p.get("title") or not p.get("paras"): E.append(f"Reading section {i}: a passage has no title or text")
        if mod == "AC" and not 650 <= n <= 1050: W.append(f"Reading passage {i}: {n} words (Academic: 700-950 each)")
    if mod == "GT" and not 1700 <= total <= 3000: W.append(f"Reading: {total} words in all (General Training: about 2,000-2,700)")
    groups("Reading", secs, lambda s: " ".join(x for p in s.get("passages") or [] for x in p.get("paras") or []))

    # ---- writing
    w = test.get("writing") or {}
    for k, mn in (("task1", 150), ("task2", 250)):
        t = w.get(k) or {}
        if not t.get("prompt") or not t.get("kind"): E.append(f"Writing {k}: no prompt or kind")
        if t.get("min") != mn: E.append(f"Writing {k}: min must be {mn}")
    if mod == "AC":
        c = (w.get("task1") or {}).get("chart")
        if not c: E.append("Writing task1: an Academic test needs a 'chart'")
        else:
            if c.get("type") not in ("bar", "line", "table", "pie"): E.append(f"Writing task1 chart: type {c.get('type')!r}")
            labels, series = c.get("labels") or [], c.get("series") or []
            if not c.get("title") or len(labels) < 2 or not series: E.append("Writing task1 chart: needs title, labels and series")
            for s in series:
                d = s.get("data") or []
                if not s.get("name") or len(d) != len(labels) or not all(isinstance(x, (int, float)) for x in d): E.append(f"Writing task1 chart: series {s.get('name')!r} must have one number per label")
            if c.get("type") == "pie" and len(series) > 3: W.append("Writing task1 chart: more than three pies is hard to read")
    return E, W


if __name__ == "__main__":
    ids = [a for a in sys.argv[1:] if not a.startswith("-")]
    if not ids:
        listed = [t["id"] for t in json.load(open(os.path.join(HERE, "index.json")))["tests"]]
        extra = sorted(os.path.basename(f)[:-5] for f in glob.glob(os.path.join(HERE, "gt*.json")) + glob.glob(os.path.join(HERE, "ac*.json")))
        ids = listed + [x for x in extra if x not in listed]
    bad = 0
    for tid in ids:
        try:
            E, W = check(json.load(open(os.path.join(HERE, tid + ".json"), encoding="utf-8")), tid)
        except Exception as ex:  # unreadable JSON etc.
            E, W = [f"cannot read: {ex}"], []
        print(f"{tid}: {'ERROR' if E else 'ok'}" + (f" ({len(W)} warnings)" if W and not E else ""))
        for e in E: print("   ERROR", e)
        for x in W: print("   warn ", x)
        bad += bool(E)
    print(f"{len(ids) - bad} of {len(ids)} tests can be published")
    sys.exit(1 if bad else 0)
