async(page)=>{
const origin=new URL(page.url()).origin;await page.goto(origin+'/control/auth/menu');await page.waitForLoadState('networkidle');const tree=page.locator('.dd').first();const id=await tree.getAttribute('id');
await page.locator('.'+id+'-tree-tools[data-action=collapse]').click();if(!await tree.locator('.dd-collapsed').count())throw Error('Collapse tree');await page.locator('.'+id+'-tree-tools[data-action=expand]').click();if(await tree.locator('.dd-collapsed').count())throw Error('Expand tree');
const before=await tree.evaluate(e=>jQuery(e).nestable('serialize'));const handles=tree.locator(':scope > ol.dd-list > li.dd-item > .dd-handle');
if(await handles.count()<2)throw Error('Tree fixture roots absent');const source=handles.first(),target=handles.last();const a=await source.boundingBox(),b=await target.boundingBox();await page.mouse.move(a.x+40,a.y+a.height/2);await page.mouse.down();await page.mouse.move(a.x+40,a.y+a.height/2+8,{steps:3});await page.mouse.move(b.x+40,b.y+b.height+12,{steps:18});await page.mouse.up();
const serialized=await tree.evaluate(e=>jQuery(e).nestable('serialize'));if(JSON.stringify(serialized)===JSON.stringify(before))throw Error('Actual drag did not reorder tree');
const save=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/auth/menu'));await page.locator('.'+id+'-save').click();if((await save).status()!==200)throw Error('Tree save');await page.waitForFunction(()=>jQuery.active===0);
const state=await(await page.request.get(origin+'/control/parity/state')).json();const expected=[];const visit=(rows,parent=0)=>rows.forEach((r,i)=>{expected.push({id:r.id,parent,order:expected.length+1});if(r.children)visit(r.children,r.id);});visit(serialized);
for(const item of expected){const actual=state.menus.find(x=>x.id===item.id);if(!actual||Number(actual.parent_id)!==Number(item.parent))throw Error('Tree parent persistence');}
await page.screenshot({path:'OUTPUT_DIR/tree-reordered.png',fullPage:true});return{tree_rendered:true,collapse_expand:true,actual_pointer_drag_changed_serialization:true,save_mutation_database_parents_checked:true};
}
