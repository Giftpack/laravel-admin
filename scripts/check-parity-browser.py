#!/usr/bin/env python3
"""Browser parity checks in Chromium via the repository's locked Playwright CLI (never a test double)."""
import argparse,json,pathlib,subprocess,tempfile,time,re,zipfile
p=argparse.ArgumentParser();p.add_argument('url');p.add_argument('engine',choices=['pgsql','mysql']);p.add_argument('--output-prefix',default='parity');p.add_argument('--stage',action='append');args=p.parse_args()
root=pathlib.Path(__file__).resolve().parent.parent
out=root/'output/playwright'/args.output_prefix/args.engine;out.mkdir(parents=True,exist_ok=True)
session=args.output_prefix+'-'+args.engine+'-'+str(int(time.time()));base=['node',str(root/'tests/browser/node_modules/@playwright/cli/playwright-cli.js'),'--session',session]
results={'engine':args.engine,'base_url':args.url,'cli':'0.1.25','checks':{},'passed':False}
def run(command,label):
 c=subprocess.run(base+command,cwd=root,text=True,capture_output=True,timeout=55);s=c.stdout+c.stderr;(out/(label+'.txt')).write_text(s)
 if c.returncode or '### Error' in s:raise RuntimeError(label+': '+s[:2500])
 m=re.search(r'### Result\s*\n(.*?)\n###',s,re.S);return json.loads(m.group(1))if m else {'completed':True}
def preserve_trace():
 entries=re.findall(r'\]\((\.playwright-cli/traces/[^)]+\.(?:trace|network))\)',(out/'tracing-stop.txt').read_text())
 if not entries:return
 refs=set()
 def collect(value):
  if isinstance(value,dict):
   for key,item in value.items():
    if key in ['sha1','_sha1'] and isinstance(item,str):refs.add(item)
    else:collect(item)
  elif isinstance(value,list):
   for item in value:collect(item)
 with zipfile.ZipFile(out/'browser-trace.zip','w',zipfile.ZIP_DEFLATED)as archive:
  for entry in entries:
   source=root/entry;archive.write(source,'trace'+source.suffix)
   for line in source.read_text().splitlines():
    try:collect(json.loads(line))
    except json.JSONDecodeError:pass
  for ref in sorted(refs):
   source=root/'.playwright-cli/traces/resources'/ref
   if source.is_file():archive.write(source,'resources/'+ref)
 results['trace_archive']='browser-trace.zip'
def check(label,source):
 wrapped='''async(page)=>{const errors=[],consoleErrors=[],network=[],failed=[];const err=e=>errors.push(e.message),ce=m=>{if(m.type()==='error')consoleErrors.push({text:m.text(),location:m.location()});},net=r=>{network.push({method:r.request().method(),url:r.url(),status:r.status(),type:r.request().resourceType()});},fail=r=>failed.push({url:r.url(),type:r.resourceType(),failure:r.failure()});page.on('pageerror',err);page.on('console',ce);page.on('response',net);page.on('requestfailed',fail);try{const result=await ('''+source+''')(page);if(errors.length)throw Error('Uncaught browser errors '+errors.join(';'));const required=['script','stylesheet','font','image'];const bad=network.filter(r=>r.status>=400&&required.includes(r.type)).concat(failed.filter(r=>required.includes(r.type)));if(bad.length)throw Error('Required browser resource failed '+JSON.stringify(bad));return {...result,uncaught_errors:errors,console_errors:consoleErrors,network,failed_requests:failed};}finally{page.off('pageerror',err);page.off('console',ce);page.off('response',net);page.off('requestfailed',fail);}}'''
 wrapped=wrapped.replace('OUTPUT_DIR',str(out))
 with tempfile.NamedTemporaryFile(mode='w',suffix='.js') as f:
  f.write(wrapped);f.flush();results['checks'][label]=run(['run-code','--filename',f.name],label)
 print(label+': passed',flush=True)
try:
 run(['open',args.url+'/control/auth/login'],'open');run(['tracing-start'],'tracing-start')
 check('login',"""async(page)=>{await page.getByRole('textbox',{name:'admin.username',exact:true}).fill('admin');await page.getByRole('textbox',{name:'admin.password',exact:true}).fill('fixture-password');await page.getByRole('button',{name:'admin.login',exact:true}).click();await page.waitForLoadState('networkidle');await page.goto(new URL('/control/parity/fields/scalar',page.url()).href);await page.getByRole('heading',{name:'Browser fields scalar'}).waitFor();await page.waitForLoadState('networkidle');return{authenticated:true,version:page.context().browser().version()};}""")
 for label in args.stage or ['fields','nested','uploads','management','logs','tree','widgets','diagnostics','security']:
  check(label,(root/('tests/browser/parity/'+label+'-check.js')).read_text())
 results['passed']=True
except Exception:
 run(['snapshot'],'failure-snapshot');raise
finally:
 try:run(['tracing-stop'],'tracing-stop');preserve_trace()
 finally:run(['close'],'close');(out/'results.json').write_text(json.dumps(results,indent=2)+'\n')
