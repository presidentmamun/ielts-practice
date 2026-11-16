# IELTS Practice Desk

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
