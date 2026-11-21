async (page) => {
const origin=new URL(page.url()).origin;
await page.goto(origin+'/control/multi-grids');
await page.waitForFunction(()=>jQuery.active===0);
const stats=()=>page.evaluate(()=>{
 const events=jQuery._data(document,'events')||{};
 const submit=(events.click||[]).filter(h=>h.namespace==='adminInline').map(h=>h.selector);
 const owners=Object.keys(jQuery.admin.inlineEditLifecycle?.owners||{});
 return {submit,owners,orphaned:owners.filter(owner=>!document.querySelector('.'+owner)),lifecycle:(events['pjax:end']||[]).filter(h=>h.namespace==='adminInlineLifecycle').length,host:(events.click||[]).filter(h=>h.namespace==='issue11LifecycleHost').length};
});
await page.evaluate(()=>{window.issue11LifecycleHostClicks=0;jQuery(document).on('click.issue11LifecycleHost','.ie-submit',()=>window.issue11LifecycleHostClicks++);});
const initial=await stats();if(initial.submit.length!==4||initial.owners.length!==4||initial.lifecycle!==1||initial.host!==1)throw Error('Unexpected initial two-grid ownership');
let writes=0;const observed=[];
const observe=r=>{const path=new URL(r.url()).pathname;if(r.request().method()==='POST'&&/\/(?:other-)?records\/1$/.test(path)){writes++;observed.push(path);}};page.on('response',observe);
const state=async()=>await(await page.request.get(origin+'/control/fixture-state')).json();
const editBoth=async(round)=>{
 for(const side of ['left','right']){
  const path='/control/'+(side==='left'?'records':'other-records')+'/1';
  const key=side==='left'?'records':'other_records',other=side==='left'?'other_records':'records';const before=await state();
  const title='Lifecycle '+side+' '+round+' '+Date.now();
  const trigger=page.locator('#multi-'+side+' td.column-title a');await trigger.click();const popup=page.locator('#'+await trigger.getAttribute('aria-describedby'));await popup.getByRole('textbox').fill(title);
  const pending=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===path);
  await popup.getByRole('button',{name:'admin.submit',exact:true}).click();if((await pending).status()!==200)throw Error('Lifecycle update failed');await popup.waitFor({state:'hidden'});
  const after=await state();if(after[key].find(r=>r.id===1).title!==title||after[other].find(r=>r.id===1).title!==before[other].find(r=>r.id===1).title)throw Error('Lifecycle cross-grid mutation');
 }
};
const renders=[initial];
for(let round=0;round<4;round++){
 if(round){
  const old=await page.locator('#multi-left .grid-table').getAttribute('id');
  const refreshed=page.waitForResponse(r=>r.request().headers()['x-pjax']==='true');await page.getByRole('link',{name:'',exact:true}).click();await refreshed;
  await page.waitForFunction(id=>document.querySelector('#multi-left .grid-table')?.id!==id,old);await page.waitForFunction(()=>jQuery.active===0);
  const current=await stats();if(current.submit.length!==4||current.owners.length!==4||current.lifecycle!==1||current.host!==1||current.orphaned.length)throw Error('Owned handlers grew or live/host handlers were removed');renders.push(current);
 }
 await editBoth(round);
}
// Add ordinary host navigation links, then click them: the existing PJAX listener
// performs actual HTTP/DOM replacement, including a page with no inline fields.
const navigate=async(path,label)=>{
 await page.evaluate(({url,label})=>{const a=document.createElement('a');a.id='issue11-lifecycle-navigation';a.href=url;a.textContent=label;document.querySelector('.content-header').append(a);},{url:origin+path,label});
 const response=page.waitForResponse(r=>r.request().headers()['x-pjax']==='true'&&new URL(r.url()).pathname===path);
 await page.getByRole('link',{name:label,exact:true}).click();await response;await page.waitForFunction(()=>jQuery.active===0);
};
await navigate('/control/gallery','Lifecycle gallery');const empty=await stats();
if(empty.submit.length||empty.owners.length||empty.host!==1||empty.lifecycle!==1||await page.locator('.popover .ie-content').count())throw Error('Orphan ownership/popover survived navigation away');
await navigate('/control/multi-grids','Lifecycle return');const returned=await stats();if(returned.submit.length!==4||returned.owners.length!==4||returned.host!==1||returned.lifecycle!==1)throw Error('Live grids not restored');
await editBoth('returned');page.off('response',observe);
const hostClicks=await page.evaluate(()=>window.issue11LifecycleHostClicks);
if(writes!==10||hostClicks!==10||observed.filter(p=>p==='/control/records/1').length!==5||observed.filter(p=>p==='/control/other-records/1').length!==5)throw Error('Duplicate or missing scoped lifecycle write/host event');
await page.evaluate(()=>jQuery(document).off('click.issue11LifecycleHost','.ie-submit'));
await page.screenshot({path:'output/playwright/issue11-lifecycle.png',fullPage:true});
return {submit_counts:renders.map(r=>r.submit.length),owner_counts:renders.map(r=>r.owners.length),lifecycle_counts:renders.map(r=>r.lifecycle),host_counts:renders.map(r=>r.host),away_submit:empty.submit.length,away_owners:empty.owners.length,restored_submit:returned.submit.length,exact_write_requests:writes,host_clicks:hostClicks,own_resource_paths:observed,orphaned_selectors:0,both_live_grids_work:true};
}
