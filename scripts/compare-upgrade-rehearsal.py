#!/usr/bin/env python3
import csv, hashlib, json, pathlib, sys
root=pathlib.Path(sys.argv[1])
immediate=json.loads((root/'upgraded-before-behavior.json').read_text()); before=json.loads((root/'before.json').read_text()); after=json.loads((root/'after.json').read_text()); restored=json.loads((root/'restored.json').read_text())
assert before['framework']=='12.25.0' and after['framework']=='13.43.0' and restored['framework']=='12.25.0'
assert before['php'].startswith('8.4.') and after['php']=='8.5.13' and restored['php']==before['php']
assert before['tables']==immediate['tables']==after['tables']==restored['tables'],'Existing IDs/grants/relations/data changed'
assert before['files']==restored['files'],'Rollback customizations/uploads changed'
merge=json.loads((root/'selective-header-merge.json').read_text()); expected=dict(before['files']); expected['resources/views/vendor/admin/partials/header.blade.php']=merge['after_sha256'];assert expected==immediate['files']==after['files'],'Unexpected customization/upload delta'; assert merge['custom_marker_preserved']
assert before['composer_lock']==restored['composer_lock'] and before['composer_lock']!=after['composer_lock']
assert before['sessions']>0
for name in ['baseline','upgraded','rollback']:
 data=list(csv.reader((root/(name+'.csv')).read_text(encoding='utf-8-sig').splitlines()))
 assert data[1:]==[['41','PRESERVED-RECORD']],(name,data)
assert hashlib.sha256((root/'baseline.csv').read_bytes()).hexdigest()==hashlib.sha256((root/'rollback.csv').read_bytes()).hexdigest()
out={'status':'passed','same_installation':str(root/'app'),'before_runtime':before['php'],'after_runtime':after['php'],'rollback_runtime':restored['php'],'tables':len(before['tables']),'customized_and_upload_hashes':len(before['files']),'policy':'preserve PHP serialization/key/cookie configuration; invalidate sessions and caches at both transitions; fresh reauthentication verified','database_backup_sha256':hashlib.sha256((root/'database.sql').read_bytes()).hexdigest(),'application_backup_sha256':hashlib.sha256((root/'application-backup.tar').read_bytes()).hexdigest()}
(root/'result.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out,indent=2))
