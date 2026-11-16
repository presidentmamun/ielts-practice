# How to write one practice test

One test = one JSON file `dev/<id>.json` (`gt7.json`, `ac1.json` …). Everything must be **original**: never copy or
paraphrase real IELTS / Cambridge material, real articles or real companies. Invent places, people and organisations.
British spelling. Level and style: as close to the real exam as you can.

Check your file with `python dev/check_tests.py <id>` and fix every ERROR (and length warnings) before you finish.

## Shape

```json
{"id": "gt7", "module": "GT", "title": "General Training Test 7",
 "listening": {"parts": [PART, PART, PART, PART]},
 "reading": {"sections": [SECTION, SECTION, SECTION]},
 "writing": {"task1": TASK, "task2": TASK}}
```
`module` is `"GT"` or `"AC"`; titles are `General Training Test N` / `Academic Test N`.

### Listening PART (4 of them, 10 questions each, numbered 1-40 across the parts)

```json
{"title": "Part 1", "context": "A woman phones a dental surgery to register as a new patient.", "range": "1-10",
 "speakers": {"Receptionist": "aus_f", "Priya": "airy"},
 "script": [["Receptionist", "Good morning, Hillside Dental…"], ["Priya", "Hi, I'd like to…"]],
 "groups": [GROUP, …]}
```
- Part 1: everyday conversation, 2 speakers (form / notes completion). Part 2: one speaker, everyday talk (tour, radio
  item, announcement). Part 3: 2-3 speakers, study/training discussion. Part 4: one speaker, lecture.
- **Length: 620-800 words of script per part** (about 4-5 minutes); do not go under 600. Natural speech: short turns in conversations,
  hesitations ("well", "let me see"), self-corrections, and **distractors** (a wrong value is mentioned, then corrected
  or rejected). Answers come in question order. Say what the listener needs, never read the questions aloud.
- Voices (use these exact keys; mix accents across the test): British male `buttery`, `bright`; British female `airy`,
  `rounded`; American female `glassy`; American male `deep`; Australian `aus_f`, `aus_m`; New Zealand `nz_f`, `nz_m`.
  Every name used in `script` must be a key of `speakers`.
- Write the script for a text-to-speech voice: no stage directions, no brackets, no bullet points. Spell names as
  `H-A-R-P-E-R`. Phone numbers as digit groups (`07739 554 210`). Money as `24 pounds 50`. Times as `6.45`.
  Postcodes like `TN8 4RD`. Other numbers in digits.
- Every gap answer must appear in the script **exactly as the learner should write it** (same word form).

### Reading SECTION (3 of them, 40 questions numbered 1-40)

```json
{"title": "Section 1", "range": "1-14",
 "passages": [{"title": "…", "paras": ["A  First paragraph…", "B  Second…"]}],
 "groups": [GROUP, …]}
```
- **General Training** (about 2,200-2,600 words in all): Section 1 (Q1-14) two or three short everyday texts
  (notices, adverts, timetables written as prose); Section 2 (Q15-27) two work-related texts; Section 3 (Q28-40) one
  long general-interest text of 850-1,000 words. Titles `Section 1/2/3`.
- **Academic**: three passages of **800-950 words each** on different academic subjects (science, history, society,
  technology, nature, psychology…), written like a serious magazine or journal for non-specialists, each harder than
  the one before. Titles `Passage 1/2/3`; ranges `1-13`, `14-26`, `27-40`. One passage object per section.
- Start paragraphs with a letter and two spaces (`"A  …"`) whenever a question refers to paragraphs.
- Every gap answer must be words taken **unchanged from the passage**.

### GROUP (a block of questions of one type)

Every item has `n` (number), `answer`, and `why` (one or two sentences that justify the answer by pointing at the
text or recording; also say why a tempting wrong answer is wrong when there is one). Every group has `instr`
(the instruction the candidate sees, in exam wording).

```json
{"type": "gap", "instr": "Complete the notes below. Write ONE WORD AND/OR A NUMBER for each answer.",
 "heading": "Hillside Dental: new patient form",
 "items": [{"n": 1, "before": "Surname:", "after": "", "answer": ["Okafor"], "why": "…"}]}
```
`gap`: `before` + blank + `after` make the line (either may be `""`). `answer` is a list of every spelling to accept
(`["24.50", "24.5"]`). At most three words. `heading` is optional (title of the form/notes/summary).

```json
{"type": "mcq", "instr": "Choose the correct letter, A, B or C.",
 "items": [{"n": 11, "q": "Why was the opening delayed?", "options": ["…", "…", "…"], "answer": "B", "why": "…"}]}
```
`mcq`: 3 options in Listening, 4 in Reading. `answer` is the letter.

```json
{"type": "tfng", "instr": "Do the following statements agree with the information in the text? Choose TRUE, FALSE or NOT GIVEN.",
 "items": [{"n": 8, "q": "…", "answer": "NOT GIVEN", "why": "…"}]}
```
`tfng` answers: `TRUE` / `FALSE` / `NOT GIVEN`. `ynng` (writer's views, Academic): `YES` / `NO` / `NOT GIVEN`.

```json
{"type": "match", "instr": "Which paragraph contains the following information? Choose the correct letter, A-F.",
 "options": [{"k": "A", "t": "Paragraph A"}, {"k": "B", "t": "Paragraph B"}],
 "items": [{"n": 20, "q": "a reason for…", "answer": "B", "why": "…"}]}
```
`match`: use it for matching information to paragraphs, headings to paragraphs (options = the headings, with one or
two extra), people/places to statements, and Listening matching. Keys are capital letters.
Matching headings is welcome (Academic especially): the option keys A, B, C… label the headings, and each item's `q` names
the paragraph (`"Paragraph B"`). It does not matter that paragraphs also have letters.

Mix the types as the real test does: each Listening part one or two groups; each Reading section two or three groups.
Spread the correct letters and TRUE/FALSE/NOT GIVEN answers evenly; make NOT GIVEN genuinely not given.

### Writing TASK

```json
"task1": {"kind": "letter (formal)", "min": 150, "prompt": "You recently…\n\nWrite a letter to… In your letter:\n- …\n- …\n- …\n\nWrite at least 150 words. You do NOT need to write any addresses.\n\nBegin your letter as follows:\nDear Sir or Madam,"},
"task2": {"kind": "opinion essay (agree/disagree)", "min": 250, "prompt": "…statement…\n\nTo what extent do you agree or disagree?\n\nGive reasons for your answer and include any relevant examples from your own knowledge or experience.\n\nWrite at least 250 words."}
```
- GT task 1 is a letter (`letter (formal)`, `letter (semi-formal)` or `letter (informal)`) with three bullet points.
- **Academic task 1 describes data** and needs a `chart` next to the prompt:

```json
"task1": {"kind": "line graph report", "min": 150,
  "prompt": "The graph below shows…\n\nSummarise the information by selecting and reporting the main features, and make comparisons where relevant.\n\nWrite at least 150 words.",
  "chart": {"type": "line", "title": "Households with internet access, 2000-2020", "unit": "%",
            "labels": ["2000", "2005", "2010", "2015", "2020"],
            "series": [{"name": "Urban", "data": [22, 41, 63, 80, 91]}, {"name": "Rural", "data": [6, 15, 34, 58, 77]}]}}
```
  `type`: `line`, `bar`, `table` or `pie`. `labels` = the categories or years; each series has one number per label.
  For `pie`, each series is one pie (e.g. one year) and `labels` are the slices (numbers should add up to 100).
  Kinds: `line graph report`, `bar chart report`, `table report`, `pie chart report`. 2-4 series, 4-7 labels, with
  clear trends worth comparing. Invented but plausible data and places.
- Task 2 kinds: `opinion essay (agree/disagree)`, `discussion + opinion essay`, `advantages and disadvantages essay`,
  `problem and solution essay`, `two-part question essay`.
