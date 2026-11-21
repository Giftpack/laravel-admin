async (page) => {
await page.goto(new URL('/control/records',page.url()).href);
const literal='<b>Literal browser value '+Date.now()+'</b>';const note='Intended note '+Date.now();
await page.locator('td.column-title a').first().click();
await page.getByRole('tooltip').getByRole('textbox').fill('x');
let response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.getByRole('tooltip').getByRole('button',{name:'admin.submit',exact:true}).click();if((await response).status()!==422)throw Error('Inline validation absent');
await page.locator('.popover .error').filter({hasText:/3/}).waitFor();
await page.getByRole('tooltip').getByRole('textbox').fill(literal);
response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));
await page.getByRole('tooltip').getByRole('button',{name:'admin.submit',exact:true}).click();if((await response).status()!==200)throw Error('Inline correction failed');
await page.reload();
const title=page.locator('td.column-title .ie-display').first();if(await title.innerText()!==literal||await title.locator('b').count())throw Error('Inline text interpreted as HTML');
await page.locator('td.column-note a').first().click();await page.getByRole('tooltip').getByRole('textbox').fill(note);
response=page.waitForResponse(r=>r.request().method()==='POST' && /records\/1/.test(r.url()));await page.getByRole('tooltip').getByRole('button',{name:'admin.submit',exact:true}).click();await response;
await page.reload();const state=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
const row=state.records.find(r=>r.id===1);if(row.note!==note||row.title!==literal)throw Error('Inline handler saved wrong field');
return {inline_invalid_422_corrected_200:true,literal_text_escaped:true,two_inline_field_types_isolated:true,persisted_database_values:true};
}
