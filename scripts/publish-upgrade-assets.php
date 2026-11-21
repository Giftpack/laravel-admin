<?php
/** Hash-aware selective publication in the guarded upgrade rehearsal only. */
require '/application/tests/Support/PackageTestHost.php';
PackageTestHost::assertDisposableEnvironment();
if (!is_file('/rehearsal/.disposable')) throw new RuntimeException('Missing owned fixture');
$old='/baseline/resources/assets';$new='/application/resources/assets';$target='/rehearsal/app/public/vendor/laravel-admin';
$result=['replaced'=>[],'added'=>[],'custom_preserved'=>[],'merge_required'=>[]];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($new,FilesystemIterator::SKIP_DOTS)) as $f){
 if(!$f->isFile())continue;$relative=substr($f->getPathname(),strlen($new)+1);$to=$target.'/'.$relative;
 if(!file_exists($to)){$kind='added';}
 elseif(is_file($old.'/'.$relative)&&hash_file('sha256',$to)===hash_file('sha256',$old.'/'.$relative)){$kind='replaced';}
 else{$result['custom_preserved'][]=$relative;if(!is_file($old.'/'.$relative)||hash_file('sha256',$f)!==hash_file('sha256',$old.'/'.$relative))$result['merge_required'][]=$relative;continue;}
 if(!is_dir(dirname($to)))mkdir(dirname($to),0777,true);copy($f,$to);$result[$kind][]=$relative;
}
file_put_contents('/rehearsal/asset-publication.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if($result['merge_required'])throw new RuntimeException('Custom security-relevant distribution paths require explicit merge: '.implode(',',$result['merge_required']));
echo json_encode($result),"\n";
