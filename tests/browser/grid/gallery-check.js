async (page) => {
await page.goto(new URL('/control/gallery',page.url()).href);
const image=page.locator('td.column-photo img');await image.waitFor();await page.waitForFunction(()=>document.querySelector('td.column-photo img').naturalWidth>0);
const carousel=page.locator('td.column-slides .carousel');const first=await carousel.locator('.item.active img').getAttribute('src');await carousel.locator('.right.carousel-control').click();
await page.waitForFunction(first=>document.querySelector('td.column-slides .item.active img').src!==first,first);
const table=page.locator('td.column-table table');if((await table.locator('th').allTextContents()).join()!=='Value,Name'||(await table.locator('td').allTextContents()).join()!=='42,<b>Literal table</b>'||await table.locator('td b').count())throw Error('Table ordering/escaping');
await page.locator('td.column-note .limit-more').first().click();await page.locator('td.column-note .limit-text:visible .text').filter({hasText:'Intended note'}).waitFor();
await page.locator('.secret-wrapper i').click();await page.locator('.secret-content').filter({hasText:'Synthetic hidden value'}).waitFor({state:'visible'});
await page.context().grantPermissions(['clipboard-read','clipboard-write']);await page.locator('.grid-column-copyable').click();
const copied=await page.evaluate(()=>navigator.clipboard.readText());if(copied!=='Action corrected')throw Error('Copy actual value');
const qrResponse=page.waitForResponse(r=>r.url().startsWith('https://api.qrserver.com/v1/create-qr-code/'),{timeout:15000});
await page.locator('.grid-column-qrcode').focus();const qr=await qrResponse;
if(qr.status()!==200 || !qr.headers()['content-type'].startsWith('image/'))throw Error('QR service response');
await page.waitForFunction(()=>document.querySelector('.popover img')?.naturalWidth>0);
if(!new URL(qr.url()).searchParams.get('data').includes('synthetic-grid-qr'))throw Error('QR encoded wrong payload');
const downloading=page.waitForEvent('download');await page.locator('td.column-download a').click();const download=await downloading;if(download.suggestedFilename()!=='fixture-image.jpg')throw Error('File download failed');
await page.screenshot({path:'output/playwright/grid-gallery.png',fullPage:true});
await page.getByRole('link',{name:'Open records',exact:true}).click();await page.getByRole('heading',{name:'Grid interaction fixture'}).waitFor();
return {downloaded_attachment:true,link_navigation:true,local_image_loaded:true,carousel_control_changed_slide:true,table_order_and_literal_text:true,limit_expanded:true,secret_toggled:true,copy_clipboard_value:true,existing_qrservice_synthetic_image_200:true};
}
