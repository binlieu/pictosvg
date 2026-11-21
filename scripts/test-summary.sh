#!/usr/bin/env bash
# Runs the test suite and prints a compact summary (one line per failure).
cd "$(dirname "$0")/.."
php artisan test "$@" 2>&1 | python3 -c "
import sys,json
raw=sys.stdin.read().strip()
try: d=json.loads(raw.splitlines()[-1])
except Exception: print(raw[-3000:]); sys.exit(1)
print({k:v for k,v in d.items() if k!='failures'})
for f in d.get('failures',[]): print('-',f['test'].split('\\\\')[-1],'|',f['message'][:600].replace('\n',' '))
sys.exit(0 if d.get('result')=='passed' else 1)"
