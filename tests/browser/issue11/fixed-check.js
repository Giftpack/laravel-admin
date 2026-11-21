async (page) => {
await page.setViewportSize({width:1280,height:300});
await page.goto(new URL('/control/records?per_page=100',page.url()).href);
await page.evaluate(()=>window.scrollTo(0,400));
const header=await page.locator('th.column-title').boundingBox();
const scrolled=await page.evaluate(()=>window.scrollY);
if(scrolled<150 || !header || header.y < -1 || header.y>2)throw Error('Header did not stick during viewport scroll');
await page.setViewportSize({width:1280,height:720});
await page.goto(new URL('/control/records?fixed=1',page.url()).href);
await page.locator('.table-fixed-left').waitFor();
const before=await page.locator('.table-fixed-left').boundingBox();
const sticky=await page.locator('.table-main thead th').first().evaluate(e=>getComputedStyle(e).position);
if(sticky!=='sticky')throw Error('Header not sticky');
if(!await page.locator('.grid-quick-create-table .quick-create .create').isVisible())throw Error('Fixed table omitted quick create');
await page.locator('.table-main').evaluate(e=>{e.scrollLeft=e.scrollWidth;});
const after=await page.locator('.table-fixed-left').boundingBox();
if(!before||!after||Math.abs(before.x-after.x)>1)throw Error('Fixed columns moved with scroll');
await page.screenshot({path:'output/playwright/issue11-fixed-columns.png',fullPage:true});
return {viewport_scroll_header_stuck:true,fixed_left_anchored_during_horizontal_scroll:true,sticky_header:true,quick_create_present_with_fixed_columns:true};
}
