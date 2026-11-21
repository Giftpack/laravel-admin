async (page) => {
await page.goto(new URL('/control/legacy-edit',page.url()).href);
await page.locator('td.column-title a').click();
await page.locator('.editable-input input').fill('x');
let response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.locator('.editable-submit').click();if((await response).status()!==422)throw Error('Legacy validation absent');
await page.locator('.editable-error-block').filter({hasText:/3/}).waitFor();
await page.locator('.editable-input input').fill('Legacy corrected');
response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));await page.locator('.editable-submit').click();if((await response).status()!==200)throw Error('Legacy correction failed');
await page.reload();if(await page.locator('td.column-title a').innerText()!=='Legacy corrected')throw Error('Legacy title not saved');
await page.locator('td.column-note a').click();await page.locator('.editable-input textarea').fill('Intended note legacy');
response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));await page.locator('.editable-submit').click();await response;
const state=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();const row=state.records.find(r=>r.id===1);if(row.title!=='Legacy corrected'||row.note!=='Intended note legacy')throw Error('Legacy wrong field');
return {legacy_editable_invalid_422_corrected_200:true,legacy_textarea_saved_intended_field:true};
}
