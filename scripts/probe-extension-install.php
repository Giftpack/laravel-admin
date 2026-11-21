<?php
/** Install a real generated extension with Composer, then discover it in a fresh PHP process. */
try {
    require (($argv[1]??'')==='--installed' ? $argv[2].'/vendor/autoload.php' : '/host/vendor/autoload.php');
    require '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition,$message){if(!$condition)throw new RuntimeException($message);}
    if (($argv[1]??'')==='--installed') {
        $disabled=($argv[3]??'')==='disabled';$directory=PackageTestHost::directory();
        foreach(['bootstrap/cache','config','public','database/migrations','storage/framework/views','storage/framework/sessions','storage/logs','app/Admin','routes'] as $path)mkdir($directory.'/'.$path,0777,true);
        file_put_contents($directory.'/.laravel-admin-test-host','native generated extension fixture');
        file_put_contents($directory.'/composer.json',json_encode(['name'=>'fixture/native-extension-host']));
        file_put_contents($directory.'/app/Admin/bootstrap.php','<?php');
        $app=Illuminate\Foundation\Application::configure(basePath:$directory)->withProviders([],withBootstrapProviders:false)->withRouting(using:fn()=>null)->withMiddleware(fn($middleware)=>null)->withExceptions(fn($exceptions)=>null)->create();
        $app->make(Illuminate\Foundation\PackageManifest::class)->vendorPath=$argv[2].'/vendor';
        $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class,function($app)use($disabled){
            $config=$app['config'];$driver=getenv('DB_CONNECTION');
            $config->set('app.env','testing');$config->set('app.key','base64:'.base64_encode(str_repeat('n',32)));$config->set('app.debug',true);
            $config->set('cache.default','array');$config->set('session.driver','file');$config->set('session.cookie','extension_session');$config->set('session.serialization','php');
            $config->set('database.default',$driver);$config->set('database.connections.'.$driver.'.url',null);
            foreach(['host'=>'DB_HOST','port'=>'DB_PORT','database'=>'DB_DATABASE','username'=>'DB_USERNAME','password'=>'DB_PASSWORD'] as $key=>$env)$config->set('database.connections.'.$driver.'.'.$key,getenv($env));
            $config->set('admin',require '/application/tests/config/admin.php');
            $config->set('admin.extensions.demo',['enable'=>!$disabled,'setting'=>'native-value']);
            $config->set('admin.operation_log.enable',false);
            foreach(Illuminate\Support\Arr::dot($config->get('admin.auth'),'auth.')as$key=>$value)$config->set($key,$value);
        });
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        ensure($app->getProvider(Fixture\Nested\Demo\DemoServiceProvider::class)!==null,'Generated Composer provider discovery');
        ensure($app->getProvider(Encore\Admin\AdminServiceProvider::class)!==null,'Core Composer provider discovery');
        ensure(Fixture\Nested\Demo\Demo::config('setting')==='native-value','Generated extension config');
        $routes=array_map(fn($route)=>$route->uri(),iterator_to_array($app['router']->getRoutes()));
        if($disabled){
            ensure(!view()->exists('demo::index'),'Disabled views registered');
            ensure(!in_array('admin/demo',$routes,true),'Disabled routes registered');
            ensure(!Illuminate\Support\ServiceProvider::pathsToPublish(Fixture\Nested\Demo\DemoServiceProvider::class),'Disabled assets registered');
            ensure(!in_array($argv[4].'/database/migrations',$app['migrator']->paths(),true),'Disabled migrations registered');
            ensure(Fixture\Nested\Demo\Demo::boot()===false,'Disabled boot');
            echo json_encode(['status'=>'passed','disabled'=>true,'checks'=>['discovered_metadata_only','no_routes_views_assets_migrations','config_before_boot']]).PHP_EOL;exit;
        }
        ensure(view()->exists('demo::index'),'Generated views');
        ensure(in_array('admin/demo',$routes,true),'Generated routes');
        ensure(str_contains(view('demo::index')->render(),'Welcome to laravel-admin'),'Generated view rendering');
        $app['db']->connection()->getSchemaBuilder()->dropAllTables();
        Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
        ensure(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])===0,'Generated migration command');
        ensure($app['db']->connection()->getSchemaBuilder()->hasTable('generated_extension_marker'),'Generated migrations loaded');
        ensure(Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'demo'])===0,'Generated asset publication');
        $asset=public_path('vendor/fixture/demo/fixture.css');ensure(file_get_contents($asset)==='.generated-extension{color:red}','Generated assets');
        file_put_contents($asset,'host-custom');Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'demo']);ensure(file_get_contents($asset)==='host-custom','Rerun overwrote customized asset');
        Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'demo','--force'=>true]);ensure(file_get_contents($asset)==='.generated-extension{color:red}','Forced assets not published');
        ensure(Illuminate\Support\Facades\Artisan::call('admin:import',['extension'=>'demo'])===0,'Native generated menu import');
        $count=Encore\Admin\Auth\Database\Menu::count();ensure(Illuminate\Support\Facades\Artisan::call('admin:import',['extension'=>'demo'])===0&&Encore\Admin\Auth\Database\Menu::count()===$count,'Native import rerun');
        echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'checks'=>['composer_installed_generated_extension','native_auto_discovery','generated_namespace_routes_config_views','migration_executed','assets_publish_preserve_force','menu_import_idempotent']],JSON_PRETTY_PRINT).PHP_EOL;exit;
    }
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['admin.extension_dir'=>storage_path('extensions')]);
    ensure(Illuminate\Support\Facades\Artisan::call('admin:extend',['extension'=>'fixture/demo','--namespace'=>'Fixture\\Nested\\Demo'])===0,'Generate extension');
    $extension=storage_path('extensions/fixture/demo');
    file_put_contents($extension.'/resources/assets/fixture.css','.generated-extension{color:red}');
    file_put_contents($extension.'/database/migrations/2026_11_21_000000_generated_extension.php',<<<'MIGRATION'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void { Illuminate\Support\Facades\Schema::create('generated_extension_marker',fn($table)=>$table->increments('id')); }
    public function down(): void { Illuminate\Support\Facades\Schema::dropIfExists('generated_extension_marker'); }
};
MIGRATION);
    foreach((new Illuminate\Filesystem\Filesystem())->allFiles($extension)as$file)if($file->getExtension()==='php')token_get_all($file->getContents(),TOKEN_PARSE);
    $dir=PackageTestHost::directory().'/native-consumer';mkdir($dir);mkdir($dir.'/vendor');
    $manifest=json_decode(file_get_contents('/host/composer.json'),true,512,JSON_THROW_ON_ERROR);$manifest['name']='fixture/generated-extension-consumer';
    $manifest['require']['fixture/demo']='1.0.0';
    array_unshift($manifest['repositories'],['type'=>'path','url'=>$extension,'options'=>['symlink'=>true,'versions'=>['fixture/demo'=>'1.0.0']]]);
    file_put_contents($dir.'/composer.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));copy('/host/composer.lock',$dir.'/composer.lock');
    $metadata=json_decode(file_get_contents('/host/vendor/composer/installed.json'),true);
    foreach($metadata['packages']as$package){$path=$dir.'/vendor/'.$package['name'];if(!is_dir(dirname($path)))mkdir(dirname($path),0777,true);symlink('/host/vendor/'.$package['name'],$path);}
    passthru('cp -R '.escapeshellarg('/host/vendor/composer').' '.escapeshellarg($dir.'/vendor/composer'),$code);ensure($code===0,'Seed metadata');copy('/host/vendor/autoload.php',$dir.'/vendor/autoload.php');
    passthru('cd '.escapeshellarg($extension).' && composer validate --strict', $code);ensure($code===0,'Generated extension Composer validation');
    foreach(['composer update --no-interaction --no-progress --no-scripts','composer install --no-interaction --no-progress --no-scripts','composer validate --no-check-publish','composer check-platform-reqs','composer audit --locked --format=json']as$command){
        passthru('cd '.escapeshellarg($dir).' && '.$command,$code);ensure($code===0,$command);
    }
    foreach(['enabled','disabled']as$mode){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --installed '.escapeshellarg($dir).' '.escapeshellarg($mode).' '.escapeshellarg($extension),$code);ensure($code===0,'Installed '.$mode.' probe');}
    echo json_encode(['status'=>'passed','extension_manifest_sha256'=>hash_file('sha256',$extension.'/composer.json'),'consumer_lock_sha256'=>hash_file('sha256',$dir.'/composer.lock'),'generated_provider_sha256'=>hash_file('sha256',$extension.'/src/DemoServiceProvider.php')]).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
