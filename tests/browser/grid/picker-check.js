async (page) => {
await page.reload();
let loads=0,writes=0;const observe=r=>{if(r.url().includes('_handle_selectable_')) loads++;if(r.request().method()==='POST' && /records\/1/.test(r.url())) writes++;};page.on('response',observe);
await page.locator('.column-owner_id .grid-selector').first().click();
await page.getByRole('dialog').getByRole('cell',{name:'Alpha',exact:true}).click();
const save=page.waitForResponse(r=>/records\/1/.test(r.url())&&r.request().method()==='POST');
await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
if((await save).status()!==200) throw Error('Owner update failed');
await page.getByRole('dialog').waitFor({state:'hidden'});
if(writes!==1 || loads!==1)throw Error('Duplicated picker handlers '+loads+'/'+writes);
await page.reload();
if(await page.locator('td.column-owner_id').first().innerText() !== '  Alpha') {
 if(!(await page.locator('td.column-owner_id').first().innerText()).includes('Alpha'))throw Error('Owner not persisted');
}
await page.locator('.column-tags .grid-selector').first().click();
for (const label of ['Alpha','Beta']) {const cell=page.getByRole('dialog').getByRole('cell',{name:label,exact:true});if(!await cell.locator('..').locator('input.select').isChecked())await cell.click();}
const many=page.waitForResponse(r=>/records\/1/.test(r.url())&&r.request().method()==='POST');
await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
if((await many).status()!==200)throw Error('Many update failed');
await page.getByRole('dialog').waitFor({state:'hidden'});
await page.reload();
const text=await page.locator('td.column-tags').first().innerText();if(!text.includes('Alpha')||!text.includes('Beta'))throw Error('Relations not persisted');
page.off('response',observe);
return {single_relation_saved:true,many_relations_saved:true,picker_single_handler:true,signed_selectable_loaded:true};
}
