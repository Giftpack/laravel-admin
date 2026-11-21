async(page)=>{
const origin=new URL(page.url()).origin;await page.goto(origin+'/control/parity/fields/nested');await page.getByRole('heading',{name:'Browser fields nested'}).waitFor();await page.waitForLoadState('networkidle');
await page.locator('[name=title]').fill('Browser nested persisted');await page.locator('[name="settings[note]"]').fill('Embedded browser');
for(const group of ['rows','children','notes']){await page.locator('#has-many-'+group+' .add').click();await page.locator('#has-many-'+group+' input[name$="[label]"]').last().fill('Browser '+group);}
for(const [selector,names]of [['.belongsto-owner_id',['Alpha']],['.belongstomany-tags',['Alpha','Beta']]]){
 await page.locator(selector+' a.select-relation').click();for(const name of names){const cell=page.getByRole('dialog').getByRole('cell',{name,exact:true});if(!await cell.locator('..').locator('input.select').isChecked())await cell.click();}await page.getByRole('dialog').getByRole('button',{name:'admin.submit',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
}
await page.screenshot({path:'OUTPUT_DIR/fields-nested.png',fullPage:true});
let saveResponse=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/parity/fields/nested'));await page.locator('button[type=submit]').filter({hasText:'admin.submit'}).first().click();await saveResponse;await page.waitForFunction(()=>jQuery.active===0);await page.waitForLoadState('networkidle');
let state=await(await page.request.get(origin+'/control/parity/state')).json();let row=state.record;
if(row.title!=='Browser nested persisted'||row.settings.note!=='Embedded browser'||Object.values(row.rows)[0]?.label!=='Browser rows'||row.children[0]?.label!=='Browser children'||row.notes[0]?.label!=='Browser notes'||row.owner?.name!=='Alpha'||row.tags.length!==2)throw Error('Nested persistence '+JSON.stringify(row));
await page.reload();await page.locator('#has-many-children input[name$="[label]"]').first().fill('');
const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/parity/fields/nested'));await page.locator('button[type=submit]').filter({hasText:'admin.submit'}).first().click();await response;await page.waitForLoadState('networkidle');await page.locator('.has-error').first().waitFor();
state=await(await page.request.get(origin+'/control/parity/state')).json();if(state.record.children[0]?.label!=='Browser children')throw Error('Invalid nested wrote child');
return{fields:{embeds:'actual nested JSON saved',table:'actual added row saved',hasMany:'child saved and invalid child rejected',morphMany:'typed child saved',belongsTo:'signed picker FK saved',belongsToMany:'signed picker2 pivots saved'},invalid_nested_no_write:true};
}
