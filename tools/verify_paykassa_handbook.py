#!/usr/bin/env python3
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]; AG=ROOT/'AGENTS.md'; D=ROOT/'docs'/'engineering'
MAX=32*1024
required=[f'{i:02d}_' for i in range(1,20)]
if not AG.exists(): raise SystemExit('FAIL: AGENTS missing')
text=AG.read_text(encoding='utf-8')
if AG.stat().st_size>=MAX: raise SystemExit(f'FAIL: AGENTS too large: {AG.stat().st_size}')
for phrase in ['Absolute payment-integrity law','Money law','Webhook law','Invoice-creation law','Testing law','Hard blockers','README guard','Definition of Done']:
    if phrase not in text: raise SystemExit(f'FAIL: missing root policy {phrase}')
files=[p.name for p in D.glob('*.md')]
for prefix in required:
    if not any(n.startswith(prefix) for n in files): raise SystemExit(f'FAIL: missing chapter {prefix}')
routes=set(re.findall(r'`(docs/engineering/[^`]+\.md)`',text))
if len(routes)<19: raise SystemExit(f'FAIL: only {len(routes)} routes')
for r in routes:
    if not (ROOT/r).exists(): raise SystemExit(f'FAIL: broken route {r}')
q=(D/'QUALITY_MATRIX.md').read_text(encoding='utf-8')
count=q.count('**10/10**')
if count<41: raise SystemExit(f'FAIL: quality matrix only {count} 10/10 entries')
for f in ['CURRENT_STATE_AUDIT.md','README_BASELINE.sha256','QUALITY_MATRIX.md']:
    if not (D/f).exists(): raise SystemExit(f'FAIL: missing {f}')
print('PASS: PayKassa handbook structure')
print(f'PASS: AGENTS size {AG.stat().st_size} bytes (< {MAX})')
print(f'PASS: routed chapters {len(routes)}')
print(f'PASS: explicit 10/10 rubric entries {count}')
print('PASS: payment/webhook/idempotency/readme policies present')
