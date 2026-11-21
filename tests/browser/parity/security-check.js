async(page)=>{
const origin=new URL(page.url()).origin;await page.goto(origin+'/control/records');await page.waitForLoadState('networkidle');
const result=await page.evaluate(async()=>{
 const check=(ok,label)=>{if(!ok)throw Error(label);};
 check(jQuery.fn.jquery==='3.8.0','jQuery version');
 let polluted=jQuery.extend(true,{},JSON.parse('{"__proto__":{"parityPolluted":true}}'));check(!({}).parityPolluted&&!polluted.parityPolluted,'jQuery prototype pollution');
 // The jQuery HTML API intentionally accepts unsafe HTML; this checks the known prefilter bypass
 // by validating unchanged self-closing markup rather than claiming it sanitizes input.
 check(jQuery.htmlPrefilter('<a/><b/>')==='<a/><b/>','jQuery HTML prefilter regression');
 const button=jQuery('<button type="button">Safe</button>').appendTo(document.body);button.attr('data-loading-text','<img src=/control/fixture-image/one onerror="window.parityButtonXss=1">');window.parityButtonXss=0;button.button('loading');await new Promise(r=>setTimeout(r,30));check(button.find('img').length===0&&button.text().includes('<img'),'CVE2024-6485');button.remove();
 const original=Object.getOwnPropertyDescriptor(document,'implementation');Object.defineProperty(document,'implementation',{configurable:true,value:{createHTMLDocument:()=>({body:{innerHTML:''}})}});
 try{const trigger=jQuery('<a title="safe">Security</a>').appendTo(document.body);trigger.popover({html:true,content:'<img src=/control/fixture-image/one onerror="window.parityPopoverXss=1"><input name="permitted" value="safe" onclick="window.parityPopoverXss=1"><form action="https://example.test/steal"><button formaction="https://example.test/steal">Submit</button></form><a href="javascript:window.parityPopoverXss=1" data-sanitize="false">Unsafe</a>',trigger:'manual'});trigger.popover('show');const pop=trigger.data('bs.popover').tip();check(pop.find('[onerror],[onclick],[formaction],[action],[data-sanitize]').length===0,'Sanitizer dangerous attributes');check(pop.find('[href^="javascript:"]').length===0,'Sanitizer executable URI');check(pop.find('input[name=permitted]').length===1,'Retained inline controls');trigger.popover('destroy');trigger.remove();}finally{if(original)Object.defineProperty(document,'implementation',original);else delete document.implementation;}
 check(window.parityButtonXss===0&&!window.parityPopoverXss,'Bootstrap payload executed');
 return{jquery_version:jQuery.fn.jquery,jquery_prototype_pollution_blocked:true,jquery_html_prefilter_unchanged:true,bootstrap_button_loading_payload_text:true,bootstrap_DOM_clobbering_parser_sanitizes:true,inline_controls_retained_sanitizer_enabled:true};
});
// Real DOMPurify bytes are loaded separately because the upload plugin's existing usage is optional.
await page.addScriptTag({url:origin+'/vendor/laravel-admin/bootstrap-fileinput/js/plugins/purify.min.js'});
result.dompurify=await page.evaluate(()=>{const safe=DOMPurify.sanitize('<svg><g/onload=alert(1)//<p>');if(/onload|<script/i.test(safe))throw Error('DOMPurify failed');let rejected=false;const el=jQuery('<div>').appendTo(document.body);try{el.pos({of:'<img src=/control/fixture-image/one onerror="window.parityPositionXss=1">'});}catch(e){rejected=true;}finally{el.remove();}if(!rejected||window.parityPositionXss)throw Error('Embedded position accepted HTML of option');return{version:DOMPurify.version,malicious_svg_removed:true,embedded_jqueryUI_of_option_rejected:true};});return result;
}
