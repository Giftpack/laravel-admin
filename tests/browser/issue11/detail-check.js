async (page) => {
await page.reload();
const responses=[]; const observe=r=>{if(r.url().includes('_handle_renderable_')) responses.push(r);};page.on('response',observe);
await page.locator('.column-detail a').first().click();
await page.getByRole('dialog').getByText('Loaded detail 1',{exact:true}).waitFor();
if(responses.length!==1 || responses[0].status()!==200 || !responses[0].url().includes('_context=')) throw Error('Modal context/transport');
await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).click();
await page.locator('.column-expanded a').first().click();
await page.locator('strong:visible').filter({hasText:'Loaded detail 1'}).waitFor();
if(responses.length!==2) throw Error('Duplicate async handler');
await page.locator('.column-expanded a').first().click();
await page.locator('.column-expanded a').first().click();
if(responses.length!==2) throw Error('Cached expand fetched again');
page.off('response',observe);
await page.locator('.column-owner_id .grid-selector').first().click();
await page.getByRole('dialog').getByText('Beta',{exact:true}).waitFor();
return {modal_signed_context_200:true,expand_signed_context_200:true,repeated_expand_cached:true};
}
