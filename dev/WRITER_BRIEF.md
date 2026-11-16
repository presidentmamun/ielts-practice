# Brief for writing one test (replace <id> with the test id you were given)

Write one complete, original IELTS-style practice test as the JSON file `dev/<id>.json`.

1. Read `dev/TEST_SPEC.md` carefully: exact JSON shape, lengths and rules.
2. Read `dev/topic_plan.json` and use ONLY the entry for <id>: it gives your module, title, the four listening
   situations (Parts 1-4 in order), the reading topics and the two writing tasks. Invent all names, places and details.
   Entries marked "(argumentative)" must contain the writer's own views so YES / NO / NOT GIVEN questions work.
   Academic Task 1: the plan gives the chart type first ("line:", "bar:", "table:", "pie:").
3. Skim `dev/used_topics.txt` so you do not repeat names or situations that already exist.
4. Choose voices so the test mixes British, Australian, New Zealand and American speakers.

Quality bar: as close to the real exam as you can. Natural speech with distractors, answers in question order,
unambiguous keys, a short "why" for every question, the full range of question types. Obey word limits in your own
instructions (ONE WORD ONLY means the key is one word). Check every answer against your own script or passage:
a wrong or debatable key is the worst possible defect.

Then run, from the project folder:  `python dev/check_tests.py <id>`
Fix every ERROR and every warning (number-format warnings for listening are fine when the number is clearly spoken)
and re-run until it prints "ok".

Rules: create or edit only `dev/<id>.json`. No other files, no build, no git.
Final message, 4 lines or fewer: checker result, word counts (listening parts, reading), and any key you are unsure about.
