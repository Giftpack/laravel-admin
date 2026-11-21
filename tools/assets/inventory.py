#!/usr/bin/env python3
"""Reconcile every bundled asset with its library family and actual captured pre19 bytes."""
from pathlib import Path
import json,hashlib
root=Path(__file__).resolve().parents[2];assets=root/'resources/assets';base=root/'.scratch/php85-laravel13-upgrade';prior=base/'pre-issue19/resources/assets'
metadata=json.loads((root/'tools/assets/library-metadata.json').read_text());groups={m['name']:{**m,'files':[]}for m in metadata}
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
for path in sorted(assets.rglob('*')):
 if not path.is_file():continue
 name=str(path.relative_to(assets));owner=next((m for m in metadata if name.startswith(m['prefix'])),None)
 if not owner:raise SystemExit('Unowned asset: '+name)
 old=prior/name;groups[owner['name']]['files'].append({'path':name,'sha256':sha(path),'baseline_sha256':sha(old)if old.exists()else None,'changed':not old.exists()or path.read_bytes()!=old.read_bytes()})
for group in groups.values():
 group['baseline_files']=sum(f['baseline_sha256']is not None for f in group['files']);group['candidate_files']=len(group['files'])
 if 'advisory'not in group:group['advisory']='Primary npm bulk query returned no matching advisory for its queried detected version; bounded evidence, not a security guarantee.'if group.get('package')else'Content-addressed vendored snapshot or notice; no invented upstream semver or definitive semver advisory-match claim.'
removed=[str(p.relative_to(prior))for p in prior.rglob('*')if p.is_file()and not(assets/p.relative_to(prior)).exists()]
report={'baseline_commit':'261a97aa','baseline_files':sum(g['baseline_files']for g in groups.values()),'candidate_files':sum(g['candidate_files']for g in groups.values()),'removed_baseline_paths':removed,'libraries':list(groups.values())}
assert report['baseline_files']==286 and not removed
(base/'references/asset-inventory.json').write_text(json.dumps(report,indent=2)+'\n')
lines=['# Bundled asset ownership, provenance and advisory ledger','','Issue19 implementation evidence; acceptance remains awaiting independent review. Every286 baseline file is retained, and every candidate path belongs to exactly one group in [asset-inventory.json](asset-inventory.json). Exact source/current hashes, upstream metadata, locked tarball integrity and local patch inputs are reviewable. CSS, fonts, sprites, images, translations, embedded plugins and notices are included.','','| Family | Baseline version | Candidate disposition/version | License | Files old/current | Primary provenance |','| --- | --- | --- | --- | --- | --- |']
for g in groups.values():lines.append('| '+g['name']+' | '+g['baseline']+' | '+g['version']+' | '+g['license']+' | '+str(g['baseline_files'])+'/'+str(g['candidate_files'])+' | [source]('+g['source']+') |')
lines+=['','## Advisory decisions','','Raw primary registry evidence: [bulk baseline/candidate advisory response](../artifacts/issue19-bulk-advisories.json), [final tool graph audit](../artifacts/issue19-npm-audit-candidate.json). Registry matching alone cannot recognize local source backports. The inventory does not claim that an empty query proves absence of vulnerabilities. Unknown upstream versions are explicit content-addressed maintenance snapshots, never silently treated as latest or upstream-supported.']
for g in groups.values():lines+=['','- **'+g['name']+'**: '+g['advisory']]
lines+=['','The baseline has no root npm manifest. Only tools/assets has a new locked development graph; consumer runtime dependencies and UI remain Composer/published-assets based. Full license notices ship with assets. Real regression scripts test both Bootstrap CVE conditions, jQuery pollution/prefilter behavior, embedded Position HTML-of rejection and DOMPurify malformed SVG sanitization, as well as actual retained controls. See [build/distribution documentation](../../../../tools/assets/README.md).','','RTL preserves its independent legacy CSS versions; it always uses the same patched JavaScript and local font family. The fixture independently generates LTR/RTL minified manifests from the actual configured asset sets. No Bootstrap/AdminLTE redesign, new provider, or upstream support claim is introduced.']
(base/'references/asset-ledger.md').write_text('\n'.join(lines)+'\n')
print(f"Reconciled {report['baseline_files']} baseline and {report['candidate_files']} candidate files in {len(groups)} groups")
