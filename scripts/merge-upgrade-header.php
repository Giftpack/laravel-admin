<?php
require '/application/tests/Support/PackageTestHost.php';PackageTestHost::assertDisposableEnvironment();
if(!is_file('/rehearsal/.disposable'))throw new RuntimeException('Unowned host');
$path='/rehearsal/app/resources/views/vendor/admin/partials/header.blade.php';$before=file_get_contents($path);
$old='<a href="{{ admin_url(\'auth/logout\') }}" class="btn btn-default btn-flat">{{ trans(\'admin.logout\') }}</a>';
$new='<form method="POST" action="{{ admin_url(\'auth/logout\') }}">'."\n".'{{ csrf_field() }}'."\n".'<button type="submit" class="btn btn-default btn-flat">{{ trans(\'admin.logout\') }}</button></form>';
if(substr_count($before,$old)!==1||!str_contains($before,'CUSTOM-HEADER'))throw new RuntimeException('Expected operator merge context');
$after=str_replace($old,$new,$before);file_put_contents($path,$after);
file_put_contents('/rehearsal/selective-header-merge.json',json_encode(['path'=>$path,'before_sha256'=>hash('sha256',$before),'after_sha256'=>hash('sha256',$after),'custom_marker_preserved'=>str_contains($after,'CUSTOM-HEADER'),'required_change'=>'GET anchor to POST form with CSRF'],JSON_PRETTY_PRINT));
