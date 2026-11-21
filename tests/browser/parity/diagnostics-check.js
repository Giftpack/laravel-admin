async(page)=>{
const origin=new URL(page.url()).origin;const matrix=[];
for(const mode of [
 {name:'en-unminified',query:'locale=en',lang:'en',rtl:false,minified:false},
 {name:'zh-TW-unminified-override',query:'locale=zh-TW&override=1',lang:'zh-TW',rtl:false,minified:false},
 {name:'ar-RTL-unminified',query:'locale=ar&rtl=1',lang:'ar',rtl:true,minified:false},
 {name:'en-minified',query:'locale=en&minified=1',lang:'en',rtl:false,minified:true},
 {name:'ar-RTL-minified',query:'locale=ar&rtl=1&minified=1',lang:'ar',rtl:true,minified:true}
]){
 await page.goto(origin+'/control/parity/fields/dates?'+mode.query);await page.waitForLoadState('networkidle');
 if(await page.locator('html').getAttribute('lang')!==mode.lang)throw Error('Language attribute '+mode.name);
 if(mode.rtl&&await page.locator('body').evaluate(e=>getComputedStyle(e).direction)!=='rtl')throw Error('RTL direction');
 if(mode.name.includes('override')&&!await page.locator('#parity-host-override').count())throw Error('Published override');
 const scripts=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(e=>e.src));if(!scripts[0].includes('plugins/jQuery/jquery.min.js'))throw Error('jQuery not first');
 if(mode.minified&&!scripts.some(s=>/\/laravel-admin(?:\.rtl)?\.min\.js\?id=/.test(s)))throw Error('Minified manifest missing');
 if(!mode.minified&&scripts.some(s=>/\/laravel-admin(?:\.rtl)?\.min\.js\?id=/.test(s)))throw Error('Unexpected minified mode');
 const input=page.locator('[name=date]');await input.fill('2026-11-23');await input.press('Tab');const normalized=(await input.inputValue()).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));if(normalized!=='2026-11-23')throw Error('Date control '+mode.name+': '+await input.inputValue());
 await page.locator('[name=DateMultiple]').click();await page.locator('.flatpickr-calendar.open').waitFor();await page.getByRole('heading',{name:'Browser fields dates'}).click();
 await page.screenshot({path:'OUTPUT_DIR/'+mode.name+'.png',fullPage:true});
 matrix.push({...mode,required_control_initializers:true,scripts});
}
// Repeat actual menu PJAX navigation and inspect handler cardinality after settled renders.
await page.goto(origin+'/control/records');await page.waitForLoadState('networkidle');const count=await page.evaluate(()=>{const e=jQuery._data(document,'events')||{};return Object.fromEntries(Object.entries(e).map(([k,v])=>[k,v.filter(h=>/adminInline/.test(h.namespace||'')).length]));});
const menuLink=page.locator('.sidebar-menu a[href="'+origin+'/control"]').first();if(!await menuLink.isVisible())await page.locator('.sidebar-menu .treeview > a').first().click();await menuLink.waitFor({state:'visible'});
for(let i=0;i<3;i++){const response=page.waitForResponse(r=>r.request().headers()['x-pjax']==='true'&&r.url().includes('/control'));await page.locator('.sidebar-menu a[href="'+origin+'/control"]').first().click();await response;await page.waitForFunction(()=>jQuery.active===0);await page.getByRole('heading',{name:'Grid interaction fixture'}).waitFor();}
const again=await page.evaluate(()=>{const e=jQuery._data(document,'events')||{};return Object.fromEntries(Object.entries(e).map(([k,v])=>[k,v.filter(h=>/adminInline/.test(h.namespace||'')).length]));});if(JSON.stringify(count)!==JSON.stringify(again))throw Error('Duplicate inline handler growth: '+JSON.stringify({before:count,after:again}));
const links=await page.locator('script[src],link[rel=stylesheet]').evaluateAll(nodes=>nodes.map(e=>e.src||e.href).filter(u=>u.includes('/vendor/laravel-admin/')));if(links.some(u=>!/[?&]v=[a-f0-9]{64}(?:&|$)/.test(u)))throw Error('Published content fingerprint absent');
const downloadPromise=page.waitForEvent('download');await page.getByRole('link',{name:/admin.export/}).first().click();const download=await downloadPromise;if(!download.suggestedFilename().endsWith('.csv')||await download.failure())throw Error('CSV download');await download.saveAs('OUTPUT_DIR/browser-export.csv');
return{matrix,repeat_menu_PJAX:3,handlers_before:count,handlers_after:again,content_fingerprints:true,actual_CSV_download:download.suggestedFilename()};
}
