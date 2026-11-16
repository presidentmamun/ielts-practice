#!/bin/sh
# Free-voice recordings for the given test ids, one wave at a time (waits for a running wave to finish first).
#   sh dev/audio_wave.sh gt9 ac3 ...
cd "$(dirname "$0")"
while [ -f audio.lock ]; do sleep 20; done
echo "$@" > audio.lock
./ttsenv/Scripts/python.exe gen_audio.py --public "$@" >> gen_audio_waves.log 2>&1
rm -f audio.lock
n=0; for t in "$@"; do for p in 1 2 3 4; do [ -f "audio_pub/$t-p$p.mp3" ] && n=$((n+1)); done; done
echo "wave $*: $n recordings present"
