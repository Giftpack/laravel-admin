#!/usr/bin/env python3
"""Run locked Playwright CLI snippets against scripts/start-grid-browser; no browser mocks."""
import argparse, json, pathlib, subprocess, tempfile, time, re
p=argparse.ArgumentParser();p.add_argument('url');p.add_argument('engine',choices=['pgsql','mysql']);p.add_argument('--output-prefix',default='issue11');args=p.parse_args();
if not re.fullmatch(r'[A-Za-z0-9_-]+',args.output_prefix):raise SystemExit('Invalid output prefix.')
root=pathlib.Path(__file__).resolve().parent.parent
cli=root/'tests/browser/node_modules/@playwright/cli/playwright-cli.js'
if not cli.is_file():raise SystemExit('Run npm ci --prefix tests/browser --ignore-scripts first.')
out=root/'output/playwright'/args.output_prefix;out.mkdir(parents=True,exist_ok=True)
session=args.output_prefix+'-'+args.engine+'-'+str(int(time.time()))
base=['node',str(cli),'--session',session]
results={'engine':args.engine,'base_url':args.url,'cli':'0.1.25','checks':{}}
def run(command,label):
    completed=subprocess.run(base+command,cwd=root,text=True,capture_output=True,timeout=55)
    text=completed.stdout+completed.stderr
    (out/(args.output_prefix+'-'+args.engine+'-'+label+'.txt')).write_text(text)
    if completed.returncode or '### Error' in text:raise RuntimeError(label+': '+text[:1800])
    match=re.search(r'### Result\s*\n(.*?)\n###',text,re.S)
    if match:return json.loads(match.group(1))
    return {'completed':True}
def check(label,source):
    source=source.replace('output/playwright/issue11-','output/playwright/'+args.output_prefix+'/'+args.output_prefix+'-'+args.engine+'-')
    wrapped='''async (page) => {const errors=[];const network=[];const onError=e=>errors.push(e.message);const onResponse=r=>{const u=new URL(r.url());if(u.pathname.startsWith('/control/'))network.push({method:r.request().method(),path:u.pathname,status:r.status(),type:r.headers()['content-type']||''});};page.on('pageerror',onError);page.on('response',onResponse);try {const result=await ('''+source+''')(page);if(errors.length)throw Error('Browser page errors: '+errors.join(';'));return {...result,browser_pageerrors:0,network};}finally{page.off('pageerror',onError);page.off('response',onResponse);}}'''
    with tempfile.NamedTemporaryFile(mode='w',suffix='.js')as f:
        f.write(wrapped);f.flush();result=run(['run-code','--filename',f.name],label)
    results['checks'][label]=result
    print(label+': passed',flush=True)
try:
    run(['open',args.url+'/control/auth/login'],'open')
    check('login', '''async (page)=>{await page.getByRole('textbox',{name:'admin.username',exact:true}).fill('operator');await page.getByRole('textbox',{name:'admin.password',exact:true}).fill('fixture-password');await page.getByRole('button',{name:'admin.login',exact:true}).click();await page.goto(new URL('/control/records',page.url()).href);await page.getByRole('heading',{name:'Grid interaction fixture'}).waitFor();return {operator_login:true,browser_version:page.context().browser().version()};}''')
    for label in ['inline','legacy','validation','multi','lifecycle','action','detail','picker','upload','tools','batch','fixed','gallery','denied']:
        check(label,(root/('tests/browser/issue11/'+label+'-check.js')).read_text())
    results['passed']=True
except Exception:
    run(['snapshot'],'failure-snapshot')
    raise
finally:
    run(['close'],'close')
    (out/(args.output_prefix+'-'+args.engine+'-results.json')).write_text(json.dumps(results,indent=2)+'\n')
