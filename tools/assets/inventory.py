#!/usr/bin/env python3
"""Describe the current bundled assets without requiring local task captures."""
from pathlib import Path
import argparse
import hashlib
import json

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, help='Write JSON to this path instead of stdout')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
assets = root / 'resources/assets'
metadata = json.loads((root / 'tools/assets/library-metadata.json').read_text())
groups = {item['name']: {**item, 'files': []} for item in metadata}
for path in sorted(assets.rglob('*')):
    if not path.is_file():
        continue
    name = str(path.relative_to(assets))
    owner = next((item for item in metadata if name.startswith(item['prefix'])), None)
    if owner is None:
        raise SystemExit('Unowned asset: ' + name)
    groups[owner['name']]['files'].append({
        'path': name,
        'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
    })
report = {
    'files': sum(len(group['files']) for group in groups.values()),
    'libraries': list(groups.values()),
}
encoded = json.dumps(report, indent=2) + '\n'
if args.output:
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(encoded)
else:
    print(encoded, end='')
