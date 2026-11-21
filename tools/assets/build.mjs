// Deterministic distribution: locked upstream bytes, exact reviewed patches, no network.
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {minify} from 'terser';
const here=path.dirname(fileURLToPath(import.meta.url));
const actual=path.resolve(here,'../../resources/assets');
const checking=process.argv.includes('--check');
const target=checking?fs.mkdtempSync(path.join(os.tmpdir(),'admin-assets-check-')):actual;
fs.mkdirSync(path.join(target,'AdminLTE/bootstrap/js'),{recursive:true});
fs.mkdirSync(path.join(target,'fontawesome-iconpicker/dist/js'),{recursive:true});
fs.mkdirSync(path.join(target,'fontawesome-iconpicker/dist/css'),{recursive:true});
fs.mkdirSync(path.join(target,'licenses'),{recursive:true});
const upstream=path.join(here,'node_modules');
const copy=(from,to)=>{fs.mkdirSync(path.dirname(path.join(target,to)),{recursive:true});fs.copyFileSync(path.join(upstream,from),path.join(target,to));};
const scratch=fs.mkdtempSync(path.join(os.tmpdir(),'admin-assets-'));
try {
 fs.cpSync(path.join(upstream,'bootstrap/js'),path.join(scratch,'js'),{recursive:true});
 for(const patch of ['0003-CVE-2024-6485.patch','CVE-2025-1647.patch'])execFileSync('patch',['--batch','--fuzz=0','-p1','-i',path.join(here,'patches',patch)],{cwd:scratch,stdio:'inherit'});
 const modules=['transition','alert','button','carousel','collapse','dropdown','modal','tooltip','popover','scrollspy','tab','affix'];
 const header=fs.readFileSync(path.join(upstream,'bootstrap/dist/js/bootstrap.js'),'utf8').split('/* ========================================================================')[0];
 const bootstrap=header+modules.map(name=>fs.readFileSync(path.join(scratch,'js',name+'.js'),'utf8')).join('\n');
 fs.writeFileSync(path.join(target,'AdminLTE/bootstrap/js/bootstrap.js'),bootstrap);
 fs.writeFileSync(path.join(target,'AdminLTE/bootstrap/js/bootstrap.min.js'),(await minify(bootstrap,{format:{comments:/^!|Copyright|Licensed/}})).code+'\n');
 for(const name of ['bootstrap.css','bootstrap.min.css'])copy('bootstrap/dist/css/'+name,'AdminLTE/bootstrap/css/'+name);
 for(const name of fs.readdirSync(path.join(upstream,'bootstrap/dist/fonts')))copy('bootstrap/dist/fonts/'+name,'AdminLTE/bootstrap/fonts/'+name);
 for(const name of ['select2.full.js','select2.full.min.js'])copy('select2/dist/js/'+name,'AdminLTE/plugins/select2/'+name);
 copy('select2/dist/css/select2.min.css','AdminLTE/plugins/select2/select2.min.css');
 for(const name of fs.readdirSync(path.join(upstream,'select2/dist/js/i18n')))copy('select2/dist/js/i18n/'+name,'AdminLTE/plugins/select2/i18n/'+name);
 // Preserve the bundled Font Awesome 4 palette; backport only jQuery UI 1.12.1's
 // window/document offset guard, since jQuery3 correctly rejects offset(window).
 const oldPicker=fs.readFileSync(path.join(here,'vendor/iconpicker-261a97aa.min.js'),'utf8');
 const oldOffset='isDocument:e,offset:c.offset()||{left:0,top:0}';
 if(oldPicker.split(oldOffset).length!==2)throw Error('Iconpicker offset patch context drift');
 const oldOf='t=a(b.of),u=a.pos.getWithinInfo';
 if(oldPicker.split(oldOf).length!==2)throw Error('Iconpicker selector patch context drift');
 fs.writeFileSync(path.join(target,'fontawesome-iconpicker/dist/js/fontawesome-iconpicker.min.js'),oldPicker.replace(oldOffset,'isDocument:e,offset:d||e?{left:0,top:0}:c.offset()||{left:0,top:0}').replace(oldOf,'t="string"==typeof b.of?a(document).find(b.of):a(b.of),u=a.pos.getWithinInfo')); 
 fs.copyFileSync(path.join(here,'vendor/iconpicker-261a97aa.min.css'),path.join(target,'fontawesome-iconpicker/dist/css/fontawesome-iconpicker.min.css'));
 fs.copyFileSync(path.join(here,'vendor/iconpicker-LICENSE'),path.join(target,'licenses/fontawesome-iconpicker.txt'));
 for(const [from,to]of [['build/css/bootstrap-datetimepicker.min.css','build/css/bootstrap-datetimepicker.min.css'],['build/js/bootstrap-datetimepicker.min.js','build/js/bootstrap-datetimepicker.min.js']])copy('eonasdan-bootstrap-datetimepicker/'+from,'eonasdan-bootstrap-datetimepicker/'+to);
 copy('jquery-pjax/jquery.pjax.js','jquery-pjax/jquery.pjax.js');
 for(const name of ['toastr.min.js','toastr.min.css'])copy('toastr/build/'+name,'toastr/build/'+name);
 // Keep the historical filename as a compatibility alias; it contains the same patched current bytes.
 for(const [from,to] of [['jquery/dist/jquery.js','AdminLTE/plugins/jQuery/jquery.js'],['jquery/dist/jquery.min.js','AdminLTE/plugins/jQuery/jquery.min.js'],['jquery/dist/jquery.min.js','AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'],['dompurify/dist/purify.js','bootstrap-fileinput/js/plugins/purify.js'],['dompurify/dist/purify.min.js','bootstrap-fileinput/js/plugins/purify.min.js'],['moment/min/moment-with-locales.js','moment/min/moment-with-locales.js'],['moment/min/moment-with-locales.min.js','moment/min/moment-with-locales.min.js']])copy(from,to);
 const rtl=fs.readFileSync(path.join(here,'vendor/AdminLTE.rtl-261a97aa.min.css'),'utf8').replace(/^@import url\(https:\/\/fonts\.googleapis\.com[^;]+;@import '[^']+';/,'');
 fs.mkdirSync(path.join(target,'AdminLTE/dist/css'),{recursive:true});fs.writeFileSync(path.join(target,'AdminLTE/dist/css/AdminLTE.rtl.min.css'),rtl);
 for(const [name,license] of [['jquery','LICENSE.txt'],['bootstrap','LICENSE'],['dompurify','LICENSE'],['moment','LICENSE'],['select2','LICENSE.md'],['jquery-pjax','LICENSE'],['eonasdan-bootstrap-datetimepicker','LICENSE']])copy(name+'/'+license,'licenses/'+name+'.txt');
 const files=(dir,prefix='')=>fs.readdirSync(dir,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name,'en')).flatMap(f=>f.isDirectory()?files(path.join(dir,f.name),prefix+f.name+'/'):[prefix+f.name]);
 const digest=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
 const distribution=Object.fromEntries(files(actual).map(file=>[file,digest(path.join(actual,file))]));
 if(checking){
   for(const file of files(target))if(!fs.existsSync(path.join(actual,file))||digest(path.join(target,file))!==digest(path.join(actual,file)))throw Error('Generated asset drift: '+file);
   const locked=JSON.parse(fs.readFileSync(path.join(here,'distribution-sha256.json'),'utf8'));
   if(JSON.stringify(distribution)!==JSON.stringify(locked))throw Error('Distribution inventory/hash drift');
   console.log('Verified deterministic generated bytes and '+Object.keys(distribution).length+' distributed files.');
 }else fs.writeFileSync(path.join(here,'distribution-sha256.json'),JSON.stringify(distribution,null,2)+'\n');
}finally{fs.rmSync(scratch,{recursive:true,force:true});if(checking)fs.rmSync(target,{recursive:true,force:true});}

