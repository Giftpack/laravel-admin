async (page) => {
await page.goto(new URL('/control/records',page.url()).href);
const secret='synthetic-unprojectable-private-value';
for (const [name,payload,valid] of [
 ['Validate wildcard',{rows:{[secret]:{title:'x'}},credential:secret},{rows:[{title:'Valid'}]}],
 ['Validate foreign',{foreign_value:'x',credential:secret},{foreign_value:'Valid'}]
]) {
 const before=(await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json()).records.find(r=>r.id===1).title;
 await page.locator('.grid-dropdown-actions .dropdown-toggle').first().click();await page.getByRole('link',{name,exact:true}).click();
 await page.getByRole('dialog').getByRole('textbox',{name:'admin.input Payload',exact:true}).fill(JSON.stringify(payload));
 let pending=page.waitForResponse(r=>r.url().includes('_handle_action_')&&r.request().method()==='POST');
 await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
 const response=await pending;const body=await response.json();
 if(response.status()!==200||body.status!==false||body.errors||body.toastr?.type!=='error'||JSON.stringify(body).includes(secret)||JSON.stringify(body).includes('Unsafe custom validator text'))throw Error('Unprojectable failure was accepted or leaked');
 await page.locator('.toast-message').filter({hasText:'admin.failed'}).first().waitFor();
 if(await page.getByRole('dialog').locator('.action-validation-error').count())throw Error('Unsafe field guidance exposed');
 const after=(await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json()).records.find(r=>r.id===1).title;if(after!==before)throw Error('Failed validator reached handler write');
 await page.getByRole('dialog').getByRole('textbox',{name:'admin.input Payload',exact:true}).fill(JSON.stringify(valid));
 pending=page.waitForResponse(r=>r.url().includes('_handle_action_')&&r.request().method()==='POST');await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
 if(!(await (await pending).json()).status)throw Error('Corrected custom validation rejected');
 await page.getByRole('link',{name:'Validation corrected',exact:true}).waitFor();
}
return {wildcard_failure_generic_200_no_write:true,foreign_field_failure_generic_200_no_write:true,no_secret_or_custom_message:true,correction_still_accepted:true};
}
