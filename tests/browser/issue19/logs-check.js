async(page)=>{
const origin=new URL(page.url()).origin;await page.goto(origin+'/control/auth/logs?path=parity-historical');await page.waitForLoadState('networkidle');await page.getByText('visible-marker',{exact:false}).waitFor();
const body=await page.locator('body').innerText();if(body.includes('historical-secret')||!body.includes('[REDACTED]'))throw Error('Historical display redaction');
const filter=page.locator('.filter-box');if(!await filter.isVisible())await page.locator('[data-target]').filter({has:page.locator('.fa-filter')}).click();
await page.locator('.filter-box input[name=path]').fill('parity-historical');const filtering=page.waitForResponse(r=>r.url().includes('path=parity-historical')&&r.request().headers()['x-pjax']==='true');await page.locator('.filter-box button.submit').click();await filtering;await page.waitForFunction(()=>jQuery.active===0);await page.getByText('visible-marker',{exact:false}).waitFor();
await page.screenshot({path:'OUTPUT_DIR/redacted-log-filter.png',fullPage:true});
const before=await(await page.request.get(origin+'/control/parity/state')).json();const historical=before.logs.find(x=>x.path==='parity-historical');if(!historical)throw Error('Historical fixture missing');
const row=page.locator('.grid-table tbody tr').first();await row.locator('.grid-dropdown-actions .dropdown-toggle').click();await row.getByRole('link',{name:'admin.delete',exact:true}).click();const deleted=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('_handle_action_'));await page.getByRole('button',{name:'admin.submit',exact:true}).click();if(!(await(await deleted).json()).status)throw Error('Log delete response');await page.getByRole('button',{name:'OK',exact:true}).click();await page.waitForLoadState('networkidle');
const after=await(await page.request.get(origin+'/control/parity/state')).json();if(after.logs.some(x=>x.id===historical.id))throw Error('Log delete persistence');
const unsafe=after.logs.filter(x=>['POST','PUT'].includes(x.method)).some(x=>JSON.stringify(x.input).includes('fixture-password')||JSON.stringify(x.input).includes('browser-password'));if(unsafe)throw Error('Audit persisted credential');
return{historical_redacted_display:true,actual_filter_PJAX:true,single_delete_database_observed:true,new_operation_credentials_redacted:true};
}
