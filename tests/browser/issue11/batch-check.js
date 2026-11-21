async (page) => {
await page.goto(new URL('/control/records?page=2',page.url()).href);
const before=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
await page.locator('.grid-table tbody tr').nth(0).locator('input[type=checkbox]').first().locator('..').click();
await page.locator('.grid-table tbody tr').nth(1).locator('input[type=checkbox]').first().locator('..').click();
await page.getByRole('button',{name:'Toggle Dropdown',exact:true}).last().click();
await page.getByRole('link',{name:'Mark selected',exact:true}).click();
await page.getByRole('button',{name:'admin.cancel',exact:true}).click();
let after=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
if(JSON.stringify(before.records)!==JSON.stringify(after.records))throw Error('Declined batch mutated');
await page.getByRole('button',{name:'Toggle Dropdown',exact:true}).last().click();await page.getByRole('link',{name:'Mark selected',exact:true}).click();
const denied=page.waitForResponse(r=>r.url().includes('_handle_action_')&&r.request().method()==='POST');
await page.getByRole('button',{name:'admin.submit',exact:true}).click();
const mixed=await denied;if(mixed.status()!==403)throw Error('Mixed authorization status '+mixed.status()+' keys='+new URLSearchParams(mixed.request().postData()).getAll('_key[]').join(','));
await page.keyboard.press('Escape');
after=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
if(JSON.stringify(before.records)!==JSON.stringify(after.records))throw Error('Mixed authorization partial mutation');
// Select only the unprotected second row after a real refresh.
await page.reload();await page.locator('.grid-table tbody tr').nth(1).locator('input[type=checkbox]').first().locator('..').click();
await page.getByRole('button',{name:'Toggle Dropdown',exact:true}).last().click();await page.getByRole('link',{name:'Mark selected',exact:true}).click();
const allowed=page.waitForResponse(r=>r.url().includes('_handle_action_')&&r.request().method()==='POST');
await page.getByRole('button',{name:'admin.submit',exact:true}).click();const res=await allowed;if(res.status()!==200||!(await res.json()).status)throw Error('Allowed batch failed');
after=await (await page.request.get(new URL('/control/fixture-state',page.url()).href)).json();
if(after.records.find(r=>r.id===4).active!==1||after.records.find(r=>r.id===3).active!==before.records.find(r=>r.id===3).active)throw Error('Batch saved wrong subject');
return {declined_no_write:true,mixed_permission_403_no_partial_write:true,allowed_batch_200_saved:true};
}
