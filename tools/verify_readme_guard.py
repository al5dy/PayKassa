#!/usr/bin/env python3
from pathlib import Path
import hashlib
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'docs'/'engineering'/'README_BASELINE.sha256'
expected={}
for line in BASE.read_text(encoding='utf-8').splitlines():
    if line.strip():
        h,name=line.split(None,1); expected[name.strip()]=h
failed=False
for rel,h in expected.items():
    p=ROOT/rel
    if not p.exists(): print(f'FAIL: protected file missing: {rel}'); failed=True; continue
    actual=hashlib.sha256(p.read_bytes()).hexdigest()
    if actual!=h:
        print(f'FAIL: unauthorized/unbaselined protected-file change: {rel}')
        print(f' expected {h}\n actual   {actual}')
        failed=True
    else: print(f'PASS: protected file unchanged: {rel}')
if failed:
    print('Do not regenerate baseline merely to silence this failure.')
    raise SystemExit(1)
print('PASS: README/CHANGELOG guard')
