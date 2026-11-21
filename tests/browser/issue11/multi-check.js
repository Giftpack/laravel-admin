async (page) => {
await page.goto(new URL('/control/multi-grids',page.url()).href);
const state=async()=>await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
const record=(s,side)=>s[side==='left'?'records':'other_records'].find(r=>r.id===1);
const ownPath=side=>'/control/'+(side==='left'?'records':'other-records')+'/1';
let totalWrites=0;const observe=r=>{if(r.request().method()==='POST'&&/\/(?:other-)?records\/1$/.test(new URL(r.url()).pathname))totalWrites++;};page.on('response',observe);
for(let round=0;round<2;round++) {
 if(round){const old=await page.locator('#multi-left .grid-table').getAttribute('id');const refresh=page.waitForResponse(r=>r.request().headers()['x-pjax']==='true');await page.getByRole('link',{name:'',exact:true}).click();await refresh;await page.waitForFunction(id=>document.querySelector('#multi-left .grid-table')?.id!==id,old);}
 const ids=await page.locator('[id]').evaluateAll(nodes=>nodes.map(n=>n.id));if(ids.length!==new Set(ids).size)throw Error('Overlapping grid DOM IDs');
 for(const side of ['left','right']) {
  const other=side==='left'?'right':'left';const box=page.locator('#multi-'+side);let before=await state();
  const value='Multi '+side+' '+round+' '+Date.now();await box.locator('td.column-title a.ie-trigger-column-title').click();await page.getByRole('tooltip').getByRole('textbox').fill(value);
  let pending=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===ownPath(side));await page.getByRole('tooltip').getByRole('button',{name:'admin.submit',exact:true}).click();if((await pending).status()!==200)throw Error('Own inline resource failed');
  let after=await state();if(record(after,side).title!==value||record(after,other).title!==record(before,other).title)throw Error('Cross-grid title overwrite');
  // The two grids render the same column and key with different selectable producers.
  before=after;const picker=page.waitForResponse(r=>r.url().includes('_handle_selectable_'));await box.locator('td.column-owner_id .grid-selector').click();const loaded=await picker;
  if(new URL(loaded.url()).searchParams.get('selectable')!==(side==='left'?'InteractionLeftPicker':'InteractionRightPicker'))throw Error('Wrong grid picker producer');
  await page.getByRole('dialog').getByRole('cell',{name:side==='left'?'Alpha':'Beta',exact:true}).click();pending=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===ownPath(side));await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();await pending;await page.getByRole('dialog').waitFor({state:'hidden'});
  after=await state();if(record(after,side).owner_id!==(side==='left'?1:2)||record(after,other).owner_id!==record(before,other).owner_id)throw Error('Cross-grid relation overwrite');
  const detailClass=side==='left'?'InteractionLeftDetail':'InteractionRightDetail';const text=(side==='left'?'Left':'Right')+' detail 1';
  pending=page.waitForResponse(r=>r.url().includes('_handle_renderable_'));await box.locator('td.column-detail a').click();let detail=await pending;if(new URL(detail.url()).searchParams.get('renderable')!==detailClass)throw Error('Wrong modal producer');await page.getByRole('dialog').getByText(text,{exact:true}).waitFor();await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).click();
  pending=page.waitForResponse(r=>r.url().includes('_handle_renderable_'));await box.locator('td.column-expanded a').click();detail=await pending;if(new URL(detail.url()).searchParams.get('renderable')!==detailClass)throw Error('Wrong expand producer');await box.locator('strong:visible').filter({hasText:text}).waitFor();if(await page.locator('#multi-'+other+' strong:visible').filter({hasText:text}).count())throw Error('Detail inserted into other grid');
 }
}
page.off('response',observe);if(totalWrites!==8)throw Error('Duplicate writes after overlapping/PJAX grids: '+totalWrites);
await page.screenshot({path:'output/playwright/issue11-multi-grid.png',fullPage:true});
return {two_grids_same_columns_and_keys:true,own_resources_only:true,own_selectable_and_modal_and_expand:true,after_pjax_no_duplicate_writes:true,exact_mutation_requests:8,dom_ids_unique:true,legacy_css_classes_retained:true};
}
