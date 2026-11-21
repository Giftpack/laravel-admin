<?php

use Encore\Admin\Admin;

class AssetDistributionTest extends TestCase
{
    public function testPublishedDistributionMatchesEveryLockedFileAndRetainsLoadOrder(): void
    {
        $root=dirname(__DIR__);
        $manifest=json_decode(file_get_contents($root.'/tools/assets/distribution-sha256.json'),true,512,JSON_THROW_ON_ERROR);
        $actual=[];
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/assets',FilesystemIterator::SKIP_DOTS))as$file){
            if($file->isFile())$actual[]=str_replace('\\','/',substr($file->getPathname(),strlen($root.'/resources/assets/')));
        }
        $expected=array_keys($manifest);sort($expected);sort($actual);$this->assertSame($expected,$actual);
        foreach($manifest as$path=>$hash){
            $this->assertSame($hash,hash_file('sha256',$root.'/resources/assets/'.$path),$path.' source');
            $this->assertSame($hash,hash_file('sha256',public_path('vendor/laravel-admin/'.$path)),$path.' published');
        }
        $this->assertSame(file_get_contents(public_path(Admin::$jQuery)),file_get_contents(public_path('vendor/laravel-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js')),'Historical published override path aliases patched bytes');
        $scripts=Admin::baseJs();
        $this->assertLessThan(array_search('vendor/laravel-admin/bootstrap3-editable/js/bootstrap-editable.min.js',$scripts),array_search('vendor/laravel-admin/AdminLTE/bootstrap/js/bootstrap.min.js',$scripts));
        $this->assertLessThan(array_search('vendor/laravel-admin/laravel-admin/laravel-admin.js',$scripts),array_search('vendor/laravel-admin/jquery-pjax/jquery.pjax.js',$scripts));
    }

    public function testAssetFingerprintFollowsActualPublishedOverridesAndPreservesHostUrls(): void
    {
        $asset='vendor/laravel-admin/laravel-admin/laravel-admin.js';$path=public_path($asset);$bytes=file_get_contents($path);
        try{
            $first=admin_asset($asset.'?existing=1#fragment');parse_str(parse_url($first,PHP_URL_QUERY),$query);
            $this->assertSame(hash('sha256',$bytes),$query['v']);$this->assertSame('1',$query['existing']);$this->assertSame('fragment',parse_url($first,PHP_URL_FRAGMENT));
            file_put_contents($path,$bytes."\n// synthetic host override\n");
            $second=admin_asset($asset.'?existing=1#fragment');$this->assertNotSame($first,$second);
            parse_str(parse_url($second,PHP_URL_QUERY),$query);$this->assertSame(hash_file('sha256',$path),$query['v']);
            $this->assertSame(asset('host.js'),admin_asset('host.js'));
            $this->assertSame(asset('https://example.test/library.js?version=1'),admin_asset('https://example.test/library.js?version=1'));
        }finally{file_put_contents($path,$bytes);}
    }
}
