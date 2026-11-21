<?php
/** Execute the immutable actual pre18 sources with only namespace isolation. */
try {
    require '/host/vendor/autoload.php';require '/application/tests/Support/PackageTestHost.php';PackageTestHost::assertDisposableEnvironment();
    function ensure($condition,$message){if(!$condition)throw new RuntimeException($message);}
    $app=PackageTestHost::create(PackageTestHost::directory());$app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $before='/application/.scratch/php85-laravel13-upgrade/issue18-before';
    $source=file_get_contents($before.'/src/Extension.php');$source=str_replace('namespace Encore\\Admin;','namespace Pre18; use Encore\\Admin\\Admin;',$source);eval(substr($source,5));
    class OriginalExtensionFixture extends Pre18\Extension {
        protected $name='original';public $menu=['title'=>'Original','path'=>'original','icon'=>'fa-list'];
        public $permission=['name'=>'Original','slug'=>'original','path'=>['original'],'method'=>['GET']];
    }
    OriginalExtensionFixture::boot();OriginalExtensionFixture::import();$rerun=null;
    try{OriginalExtensionFixture::import();}catch(Throwable$e){$rerun=$e->getMessage();}ensure(str_contains($rerun??'','already been taken'),'Original duplicate import not reproduced');
    config(['admin.extensions.original.enable'=>false]);// Disabled behavior is separately checked below.
    $disabledImport=null;try{OriginalExtensionFixture::import();}catch(Throwable$e){$disabledImport=$e->getMessage();}
    ensure(str_contains($disabledImport??'','already been taken'),'Original disabled import still executes validation');
    $out=[];
    foreach(['form','step-form']as$name){
        $stub=file_get_contents($before.'/src/Console/stubs/'.$name.'.stub');
        $class=$name==='step-form'?'StepForm':'BrokenLabel';$title=$name==='step-form'?'Title':"User's label";
        $stub=str_replace(['DummyNamespace','DummyClass','DummyTitle'],['Fixture\\Generated',$class,$title],$stub);$file=storage_path($name.'.php');file_put_contents($file,$stub);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1',$lines,$code);ensure($code!==0,'Original generated source unexpectedly compiles');$out[$name]=implode("\n",$lines);$lines=[];
    }
    echo json_encode(['status'=>'reproduced','php'=>PHP_VERSION,'extension_source_sha256'=>hash_file('sha256',$before.'/src/Extension.php'),'original_repeat_import_error'=>$rerun,'original_disabled_import_runs'=>$disabledImport,'original_generated_compile_failures'=>$out],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable$e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
