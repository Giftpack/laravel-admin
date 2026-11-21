async (page) => {
await page.goto(new URL('/control/records',page.url()).href);
await page.locator('.grid-dropdown-actions .dropdown-toggle').first().click();
await page.getByRole('link',{name:'Rename safely',exact:true}).click();
await page.getByRole('textbox', {name:'admin.input Title', exact:true}).fill('x');
await page.getByRole('textbox', {name:'admin.input Password', exact:true}).fill('synthetic-sensitive-value');
const responsePromise = page.waitForResponse(r=>r.url().includes('_handle_action_') && r.request().method()==='POST');
await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
const response=await responsePromise; const body=await response.json();
if(response.status()!==200 || body.status!==false || JSON.stringify(body).includes('synthetic-sensitive-value') || JSON.stringify(body).includes('unsafe')) throw Error('Unsafe/absent validation');
await page.getByRole('alert').filter({hasText:'allowed size or length'}).waitFor();
if(!await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).isEnabled()) throw Error('Submission stayed disabled');
await page.screenshot({path:'output/playwright/grid-safe-action-validation.png',fullPage:true});
await page.getByRole('textbox', {name:'admin.input Title', exact:true}).fill('Action corrected');
const correctedPromise=page.waitForResponse(r=>r.url().includes('_handle_action_') && r.request().method()==='POST');
await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();
const corrected=await correctedPromise;if(corrected.status()!==200 || !(await corrected.json()).status) throw Error('Correction failed');
await page.getByRole('link',{name:'Action corrected',exact:true}).waitFor();
const csrf=await page.evaluate(()=>jQuery.admin.token);
const endpoint=new URL('/control/_handle_action_',page.url()).href;
const form={_token:csrf,_action:'InteractionRename',_model:'InteractionRecord',_key:'2',title:'Forbidden direct change',password:'synthetic-sensitive-value'};
const tampered=await page.request.post(response.url(),{form});if(tampered.status()!==403)throw Error('Signed context accepted different record');
const unsigned=await page.request.post(endpoint,{form});if(unsigned.status()!==403)throw Error('Unsigned action accepted');
const noCsrf=await page.request.post(response.url(),{form:{_action:'InteractionRename',_model:'InteractionRecord',_key:'1'}});if(noCsrf.status()!==419)throw Error('Missing CSRF accepted');
return {safe_action_validation_200:true,declared_field_guidance:true,raw_secret_or_custom_message:false,form_reenabled:true,corrected_submission_200:true,tampered_record_403:true,unsigned_action_403:true,missing_csrf_419:true,signed_query_context:response.url().includes('_context='),csrf_submitted:response.request().postData().includes('_token'),response_content_type:response.headers()['content-type']};

}