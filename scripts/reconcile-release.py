#!/usr/bin/env python3
"""Inspectable inventories join accepted component evidence; never substitute inventory for behavior."""
from pathlib import Path
import hashlib,json,re,subprocess
root=Path(__file__).resolve().parent.parent; feature=root/'.scratch/php85-laravel13-upgrade'; refs=feature/'references'; artifacts=feature/'artifacts'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
reports={i:f'issue{i:02d}-validation.md' for i in range(2,21)};reports[1]='execution-baseline.md';reports[11]='issue11-cycle3-validation.md'
# Stable requirement IDs and exact owning criterion text.
criteria=[]
for p in sorted((feature/'issues').glob('*.md')):
 issue=int(p.name[:2]);text=p.read_text()
 for match in re.finditer(r'^### (C\d{2}\.\d{2}) — (.+)\n\n- \[([ x])\] ([\s\S]*?)(?=\n### |\n## |\Z)',text,re.M):
  criteria.append({'id':match[1],'title':match[2],'issue':issue,'issue_path':str(p.relative_to(root)),'criterion':match[4].strip(),'status':'independently-approved' if match[3]=='x' else 'implementation-awaiting-independent-review','evidence':str((refs/reports[issue]).relative_to(root))})
assert len(criteria)==108 and len({x['id']for x in criteria})==108
assert sum(x['status']=='independently-approved'for x in criteria)==102
# Primary source owner plus shared scope from the accepted source map.
text=(refs/'source-ownership.md').read_text();owners={m[1]:int(m[2])for m in re.finditer(r'^\| \[(src/[^\]]+)\][^\n]*?\| \[(\d{2}) —',text,re.M)}
additions={'src/Auth/Database/GrantRelation.php':9,'src/Auth/Database/TransactionalGrants.php':9,'src/Console/GeneratedSource.php':18,'src/Console/ValidatesGeneratedClass.php':18,'src/Form/UploadException.php':14,'src/Security/HandlerContext.php':9,'src/Security/InputRedactor.php':9,'src/Security/SafeValidation.php':11,'src/View/AdminCompiler.php':7};owners.update(additions)
baseline=set(subprocess.check_output(['git','ls-tree','-r','--name-only','261a97aa','src'],cwd=root,text=True).splitlines())
sources=[]
for p in sorted((root/'src').rglob('*.php')):
 path=str(p.relative_to(root));assert path in owners,path;issue=owners[path]
 sources.append({'path':path,'sha256':sha(p),'owner':issue,'disposition':'retained' if path in baseline else 'added-with-accepted-owner','evidence':str((refs/reports[issue]).relative_to(root)),'evidence_scope':'accepted component behavior; complete final core matrix; browser evidence where applicable, not individual-function coverage'})
assert len(sources)==310 and not [x for x in baseline if x.endswith('.php') and not (root/x).exists()]
nonphp=[]
for directory,issue in [('resources/views',17),('resources/assets',19),('resources/lang',17),('src/Console/stubs',18),('config',7),('database',7)]:
 for p in sorted((root/directory).rglob('*')):
  if p.is_file():nonphp.append({'path':str(p.relative_to(root)),'sha256':sha(p),'primary_owner':issue,'shared_owners':[12,13,14,19] if directory=='resources/views' else [],'evidence':str((refs/reports[issue]).relative_to(root))})
manifest=json.loads((feature/'baseline/manifest.json').read_text())
fields=[];browser={m[1]:(m[2].strip(),int(m[3]))for m in re.finditer(r'^\| `([^`]+)` \| (.*?) \| Issue(\d+) \|', (refs/'issue19-field-browser-ledger.md').read_text(),re.M)}
for name,implementation in manifest['fields'].items():
 assert name in browser,name;behavior,issue=browser[name]
 fields.append({'entry':name,'implementation':implementation,'owner':issue,'common_evidence':'issue12-validation.md','specialized_evidence':reports[issue],'browser_evidence':'issue19-field-browser-ledger.md','browser_behavior':behavior,'disposition':'behaviorally-accepted-including-browser','render_only':name in ['button','display','divider','html','id']})
browser.update({name:('actual required validation/render/uppercase database persistence',18)for name in ['customParity','customParityAlias']})
custom={name:{'behavior':row[0],'owner':row[1],'disposition':'real extension lifecycle + native required validation + Chromium uppercase DB persistence'}for name,row in browser.items()if name not in manifest['fields']}
assert len(fields)==55 and len(custom)==2
commands=[]
for cls,name in manifest['commands'].items():
 issue=owners['src/Console/'+cls+'.php'];commands.append({'command':name,'class':cls,'owner':issue,'evidence':reports[issue],'status':'behaviorally-accepted','external_provider_limit':'No live production service is certified'})
assert len(commands)==18
registries={'fields':fields,'custom_fields':custom,'filters':{'entries':manifest['filters'],'evidence':'issue10-validation.md / GridQueryTest / four full lanes','disposition':'all19 operators + six presenters retained'},'displayers':{'entries':manifest['displayers'],'evidence':'issue11-validation.md + issue11-cycle3-validation.md / GridInteractionTest / issue19-grid-verified both engines','disposition':'all20 retained; real custom extension displayer/exporter evidence in18'}}
# Reuse19 only for identical production/assets/PHP-test inputs. Tool/docs changes are named separately.
prior=json.loads((artifacts/'issue19-runtime-source.json').read_text())['files'];reused={};changed={}
for path,old in prior.items():
 p=root/path
 if not p.is_file():continue
 actual=sha(p)
 if path.startswith(('src/','resources/','config/','database/','tests/')) and path not in ['tests/README.md','src/Console/stubs/extension/README.md.stub']:
  assert actual==old,('accepted runtime/browser source changed',path);reused[path]=actual
 elif actual!=old:changed[path]={'previous':old,'current':actual}
out={'criteria':criteria,'counts':{'requirements':108,'prior_independently_approved':102,'awaiting_issue20_review':6,'source_php':len(sources),'builtin_fields':55,'custom_fields':2,'commands':18},'source':sources,'resources_stubs_config_database':nonphp,'registries':registries,'commands':commands,'unchanged_issue19_runtime_inputs':reused,'named_metadata_tool_documentation_changes_since19':changed,'limits':['No arbitrary external extension/live cloud/MongoDB/browser/locale certification','No dispatch-only/class-load-only field result accepted','Remote Actions unrun; exact local workflow commands executed','No consumer/production mutation or publication']}
(artifacts/'issue20-traceability.json').write_text(json.dumps(out,indent=2,ensure_ascii=False)+'\n')
print(json.dumps(out['counts']))
