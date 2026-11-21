async (page) => {
await page.reload();
const stamp=Date.now();
const singleName=`single-${stamp}.txt`, oneName=`one-${stamp}.txt`, twoName=`two-${stamp}.txt`;
const before=(await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json()).requests.length;
for(let i=0;i<2;i++) {
 const oldTable=await page.locator('.grid-table').getAttribute('id');
 const refreshed=page.waitForResponse(r=>r.request().headers()['x-pjax']==='true' && r.url().includes('/records'));
 await page.getByRole('link',{name:'',exact:true}).click();await refreshed;
 await page.waitForFunction(id=>document.querySelector('.grid-table')?.id!==id,oldTable);
 await page.locator('input.inline-upload').first().waitFor({state:'attached'});
}
const writes=[];const observe=r=>{if(r.request().method()==='POST' && /records\/1/.test(r.url())) writes.push(r);};page.on('response',observe);
const invalid=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.locator('td.column-file input[type=file]').first().setInputFiles({name:'bad.exe',mimeType:'application/octet-stream',buffer:Buffer.from([0,1,2,3,255,0,2,0,254])});
if((await invalid).status()!==422)throw Error('Upload validation absent');
await page.locator('td.column-file .inline-upload-error').first().filter({hasText:/txt|file|type/}).waitFor();
await page.screenshot({path:'output/playwright/issue11-upload-validation.png',fullPage:true});
const valid=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.locator('td.column-file input[type=file]').first().setInputFiles({name:singleName,mimeType:'text/plain',buffer:Buffer.from('single fixture')});
const saved=await valid;if(saved.status()!==200 || !(await saved.json()).status)throw Error('Single upload failed');
await page.locator('td.column-file .inline-upload-trigger').first().filter({hasText:singleName}).waitFor();
const singleText=await page.locator('td.column-file').first().innerText();
const many=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.locator('td.column-files input[type=file]').first().setInputFiles([{name:oneName,mimeType:'text/plain',buffer:Buffer.from('one fixture')},{name:twoName,mimeType:'text/plain',buffer:Buffer.from('two fixture')}]);
const multiple=await many;if(multiple.status()!==200 || !(await multiple.json()).status)throw Error('Multi upload failed');
await page.locator('td.column-files .inline-upload-trigger').first().filter({hasText:twoName}).waitFor();
if(writes.length!==3)throw Error('Duplicate upload requests '+writes.length);
const state=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
const record=state.records.find(r=>r.id===1);
if(!record.file.endsWith(singleName) || !record.files.some(f=>f.endsWith(oneName)) || !record.files.some(f=>f.endsWith(twoName)))throw Error('Wrong saved upload fields');
const requests=state.requests.slice(before);
if(requests.length!==3 || requests[0].files.join()!=='file' || requests[1].files.join()!=='file' || requests[2].files.join()!=='files')throw Error('Wrong upload payload fields');
page.off('response',observe);
return {repeated_pjax_renders:2,upload_columns:2,rejected_422:true,corrected_single_200:true,multiple_files_200:true,requests_exactly_three:true,intended_fields_only:true,csrf_unit_bypass:false};
}
