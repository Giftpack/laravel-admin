<?php
try {
/** Real persistent native host; invoked only by test-upgrade-container. */
$root='/rehearsal/app';
$action=$argv[1]??'';
require $action==='initialize'?'/baseline/vendor/autoload.php':$root.'/vendor/autoload.php';
require_once '/application/tests/Support/PackageTestHost.php';
PackageTestHost::assertDisposableEnvironment();
function insist($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function writeFixture($path,$text) { $path='/rehearsal/app/'.$path; if (!is_dir(dirname($path))) mkdir(dirname($path),0777,true); file_put_contents($path,$text); }
if ($action==='initialize') {
    insist(!file_exists($root),'Never initialize over an existing installation');
    foreach (['bootstrap/cache','config','database/migrations','resources/views/vendor/admin/partials','routes','public/uploads','storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','lang/en'] as $path) mkdir($root.'/'.$path,0777,true);
    file_put_contents('/rehearsal/.disposable','issue20');
    symlink('/baseline/vendor',$root.'/vendor');
    copy('/application/.scratch/php85-laravel13-upgrade/baseline/composer.json',$root.'/composer.json');
    copy('/application/.scratch/php85-laravel13-upgrade/baseline/composer.lock',$root.'/composer.lock');
    writeFixture('bootstrap/app.php', <<<'PHP'
<?php
$loader=new Composer\Autoload\ClassLoader();$loader->addPsr4('App\\',dirname(__DIR__).'/app');$loader->register(true);
Illuminate\Foundation\AliasLoader::getInstance()->alias('Admin',Encore\Admin\Facades\Admin::class);
return Illuminate\Foundation\Application::configure(basePath:dirname(__DIR__))
 ->withProviders([Encore\Admin\AdminServiceProvider::class],withBootstrapProviders:false)
 ->withRouting(web:__DIR__.'/../routes/web.php')->withMiddleware(fn($m)=>null)->withExceptions(fn($e)=>null)->create();
PHP);
    writeFixture('artisan','<?php require __DIR__."/vendor/autoload.php"; $app=require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
    writeFixture('config/app.php',"<?php return ".var_export(['env'=>'rehearsal','debug'=>false,'key'=>'base64:'.base64_encode(str_repeat('r',32)),'cipher'=>'AES-256-CBC','locale'=>'en'],true).';');
    $connection=['driver'=>getenv('DB_CONNECTION'),'host'=>getenv('DB_HOST'),'port'=>getenv('DB_PORT'),'database'=>getenv('DB_DATABASE'),'username'=>getenv('DB_USERNAME'),'password'=>getenv('DB_PASSWORD'),'charset'=>getenv('DB_CONNECTION')==='mysql'?'utf8mb4':'utf8','prefix'=>'','schema'=>'public'];
    writeFixture('config/database.php','<?php return '.var_export(['default'=>getenv('DB_CONNECTION'),'connections'=>[getenv('DB_CONNECTION')=>$connection]],true).';');
    writeFixture('config/cache.php',"<?php return ['default'=>'file','stores'=>['file'=>['driver'=>'file','path'=>storage_path('framework/cache/data')]]];");
    writeFixture('config/session.php',"<?php return ['driver'=>'file','serialization'=>'php','files'=>storage_path('framework/sessions'),'cookie'=>'rehearsal_session','lifetime'=>120,'path'=>'/','http_only'=>true,'same_site'=>'lax'];");
    writeFixture('config/view.php',"<?php return ['paths'=>[resource_path('views')],'compiled'=>storage_path('framework/views')];");
    writeFixture('config/filesystems.php',"<?php return ['default'=>'admin','disks'=>['admin'=>['driver'=>'local','root'=>public_path('uploads'),'url'=>'/uploads/','visibility'=>'public']]];");
    writeFixture('routes/web.php',"<?php Illuminate\\Support\\Facades\\Route::get('/host-owned',fn()=> 'HOST-CUSTOM-ROUTE');");
    copy('/baseline/config/admin.php',$root.'/config/admin.php');
    // Boot once to evaluate the historical published config in a real host context.
    $app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--provider'=>Encore\Admin\AdminServiceProvider::class]);
    insist(Illuminate\Support\Facades\Artisan::call('admin:install')===0,'Baseline admin install '.Illuminate\Support\Facades\Artisan::output());
    $config=require $root.'/config/admin.php';$config['name']='CUSTOM-REHEARSAL';$config['directory']=$root.'/app/Admin';$config['bootstrap']=$root.'/app/Admin/bootstrap.php';$config['upload']['disk']='admin';$config['operation_log']['enable']=true;$config['operation_log']['except']=[];
    writeFixture('config/admin.php','<?php return '.var_export($config,true).';');
    writeFixture('app/Admin/Controllers/CustomController.php', <<<'PHP'
<?php namespace App\Admin\Controllers;
class CustomController extends \Encore\Admin\Controllers\AdminController {
 protected function title(){return 'CUSTOM-CONTROLLER';}
 protected function grid(){ $g=new \Encore\Admin\Grid(new \App\Admin\Record());$g->column('id')->sortable();$g->column('name');$g->column('file');return $g; }
 protected function form(){ $f=new \Encore\Admin\Form(new \App\Admin\Record());$f->text('name')->required();$f->file('file');return $f; }
}
PHP);
    writeFixture('app/Admin/Record.php',"<?php namespace App\\Admin; class Record extends \\Illuminate\\Database\\Eloquent\\Model {protected \$table='rehearsal_records'; protected \$guarded=[]; public \$timestamps=false;}");
    file_put_contents($root.'/app/Admin/routes.php',"\n// CUSTOM-PUBLISHED-ROUTE\nRoute::group(['prefix'=>'admin','namespace'=>'App\\\\Admin\\\\Controllers','middleware'=>['web','admin']],function(\\Illuminate\\Routing\\Router \$router){\$router->resource('records','CustomController');});\n",FILE_APPEND);
    writeFixture('resources/views/vendor/admin/partials/header.blade.php',file_get_contents('/baseline/resources/views/partials/header.blade.php')."\n<!-- CUSTOM-HEADER -->");
    writeFixture('resources/views/vendor/admin/partials/footer.blade.php','<footer>CUSTOM-PUBLISHED-FOOTER</footer>');
    $translation=require '/baseline/resources/lang/en/admin.php';$translation['online']='CUSTOM-TRANSLATION';writeFixture('lang/en/admin.php','<?php return '.var_export($translation,true).';');
    writeFixture('public/vendor/laravel-admin/custom-owned.css','/* CUSTOM-ASSET */ body{--host-owned:1}');
    // Deliberately customize an existing distributed path: selective publication must preserve it.
    file_put_contents($root.'/public/vendor/laravel-admin/AdminLTE/dist/css/skins/skin-blue-light.min.css',"\n/* HOST-SKIN-CUSTOMIZATION */\n",FILE_APPEND);
    $db=$app['db']->connection();$db->getSchemaBuilder()->create('rehearsal_records',function($t){$t->increments('id');$t->string('name');$t->string('file')->nullable();});
    $db->getSchemaBuilder()->create('rehearsal_relations',function($t){$t->increments('id');$t->unsignedInteger('record_id');$t->string('value');});
    $db->table('rehearsal_records')->insert(['id'=>41,'name'=>'PRESERVED-RECORD','file'=>'files/preserved.txt']);
    $db->table('rehearsal_relations')->insert(['id'=>91,'record_id'=>41,'value'=>'PRESERVED-RELATION']);
    if (getenv('DB_CONNECTION')==='pgsql') $db->statement("SELECT setval(pg_get_serial_sequence('rehearsal_records','id'),41)");
    $actor=Encore\Admin\Auth\Database\Administrator::first();$actor->password=Illuminate\Support\Facades\Hash::make('fixture-password');$actor->avatar='images/preserved.png';$actor->save();
    $role=Encore\Admin\Auth\Database\Role::create(['name'=>'Restricted','slug'=>'restricted']);
    $permission=Encore\Admin\Auth\Database\Permission::create(['name'=>'Records browse','slug'=>'records-browse','http_method'=>['GET'],'http_path'=>'/records*']);$role->permissions()->attach($permission);
    $restricted=Encore\Admin\Auth\Database\Administrator::create(['username'=>'restricted','name'=>'Restricted','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);$restricted->roles()->attach($role);
    $menu=Encore\Admin\Auth\Database\Menu::create(['parent_id'=>0,'order'=>9,'title'=>'CUSTOM-MENU','icon'=>'fa-bars','uri'=>'records']);$menu->roles()->attach($role);
    mkdir($root.'/public/uploads/files',0777,true);mkdir($root.'/public/uploads/images',0777,true);
    file_put_contents($root.'/public/uploads/files/preserved.txt',"PRESERVED-UPLOAD\n");$im=imagecreatetruecolor(4,3);imagepng($im,$root.'/public/uploads/images/preserved.png');
    echo "Initialized existing Laravel ".app()->version()." PHP ".PHP_VERSION."\n";exit;
}
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($action==='snapshot') {
    $tables=[];foreach(['admin_users','admin_roles','admin_permissions','admin_menu','admin_role_users','admin_role_permissions','admin_user_permissions','admin_role_menu','rehearsal_records','rehearsal_relations'] as $t){$rows=$app['db']->table($t)->get()->map(fn($r)=>(array)$r)->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$tables[$t]=$rows;}
    $files=[];foreach(['app/Admin','config','resources/views/vendor/admin','lang','public/uploads','public/vendor/laravel-admin/custom-owned.css','public/vendor/laravel-admin/AdminLTE/dist/css/skins/skin-blue-light.min.css'] as $path){$f=$root.'/'.$path;$items=is_file($f)?[$f]:new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f,FilesystemIterator::SKIP_DOTS));foreach($items as $file){$file=(string)$file;if(is_file($file))$files[substr($file,strlen($root)+1)]=hash_file('sha256',$file);}}ksort($files);
    $out=['php'=>PHP_VERSION,'framework'=>app()->version(),'tables'=>$tables,'files'=>$files,'sessions'=>count(glob($root.'/storage/framework/sessions/*')),'composer_lock'=>hash_file('sha256',$root.'/composer.lock')];
    file_put_contents('/rehearsal/'.$argv[2].'.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode($out);exit;
}
if ($action==='stale-session') {
    $app->instance('env','rehearsal');$cookies=json_decode(file_get_contents('/rehearsal/transition-cookies.json'),true);$request=Illuminate\Http\Request::create('http://localhost/admin/auth/setting','GET',[],$cookies);$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$kernel->terminate($request,$response);insist($response->getStatusCode()===302,'Old session must require reauthentication');echo 'Old session rejected; reauthentication required';exit;
}
if ($action==='verify') {
    $phase=$argv[2];$app->instance('env','rehearsal');insist(!$app->runningUnitTests(),'Native CSRF must execute');
    $cookies=[];$statuses=[];
    $request=function($method,$path,$data=[],$headers=[],$files=[])use($app,&$cookies,&$statuses){$app['auth']->forgetGuards();$server=['HTTP_HOST'=>'localhost','HTTP_SEC_FETCH_SITE'=>'cross-site'];foreach($headers as $k=>$v)$server['HTTP_'.strtoupper(str_replace('-','_',$k))]=$v;$r=Illuminate\Http\Request::create('http://localhost'.$path,$method,$data,$cookies,$files,$server);$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$s=$kernel->handle($r);$kernel->terminate($r,$s);foreach($s->headers->getCookies()as$c)$cookies[$c->getName()]=$c->getValue();$statuses[]=['method'=>$method,'path'=>$path,'status'=>$s->getStatusCode()];return $s;};
    $request('GET','/admin/auth/login');$csrf=session()->token();
    insist($request('POST','/admin/auth/login',['username'=>'admin','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Login '.$phase);
    $csrf=session()->token();$profile=$request('GET','/admin/auth/setting');insist($profile->getStatusCode()===200,'Profile');
    $list=$request('GET','/admin/records');insist($list->getStatusCode()===200&&str_contains($list->getContent(),'PRESERVED-RECORD')&&str_contains($list->getContent(),'CUSTOM-PUBLISHED-FOOTER'),'Existing records/custom footer');
    insist($request('GET','/host-owned')->getContent()==='HOST-CUSTOM-ROUTE','Host route');
    insist(trans('admin.online')==='CUSTOM-TRANSLATION','Published translation');
    $ajax=['X-Requested-With'=>'XMLHttpRequest','Accept'=>'application/json'];
    insist($request('POST','/admin/records',['name'=>'FORGED'],$ajax)->getStatusCode()===419,'CSRF');
    $temp=tempnam('/tmp','upgrade-file');file_put_contents($temp,'NEW-UPGRADE-UPLOAD');
    $res=$request('POST','/admin/records',['name'=>'CREATED-'.$phase,'_token'=>$csrf],$ajax,['file'=>new Illuminate\Http\UploadedFile($temp,'rehearsal.txt','text/plain',null,true)]);
    insist($res->getStatusCode()===200&&json_decode($res->getContent(),true)['status']===true,'CRUD upload '.$res->getContent());
    $record=App\Admin\Record::where('name','CREATED-'.$phase)->firstOrFail();insist(is_file($root.'/public/uploads/'.$record->file),'Upload actual bytes');
    insist($request('PUT','/admin/records/'.$record->id,['name'=>'UPDATED-'.$phase,'_token'=>$csrf],$ajax)->getStatusCode()===200,'CRUD update');
    insist($record->fresh()->name==='UPDATED-'.$phase,'Updated database');
    insist($request('DELETE','/admin/records/'.$record->id,['_token'=>$csrf],$ajax)->getStatusCode()===200&&!$record->fresh(),'CRUD delete');
    // Keep database/file snapshot comparable across phases. Audit rows are separate expected growth.
    @unlink($root.'/public/uploads/'.$record->file);
    $logs=$request('GET','/admin/auth/logs');insist($logs->getStatusCode()===200&&$app['db']->table('admin_operation_log')->count()>0,'Operation logs');
    if(str_starts_with($phase,'upgraded'))insist(!str_contains($logs->getContent(),'fixture-password'),'Historical credentials masked after upgrade');
    file_put_contents('/rehearsal/active-cookies.json',json_encode($cookies));
    $cookies=[];$request('GET','/admin/auth/login');$token=session()->token();$request('POST','/admin/auth/login',['username'=>'restricted','password'=>'fixture-password','_token'=>$token]);
    insist($request('GET','/admin/records')->getStatusCode()===200,'Restricted browse grant');
    $denied=$request('POST','/admin/records',['name'=>'DENIED','_token'=>session()->token()],$ajax);
    insist(!App\Admin\Record::where('name','DENIED')->exists(),'Restricted mutation denied');
    $out=['phase'=>$phase,'php'=>PHP_VERSION,'framework'=>app()->version(),'cached'=>is_file($root.'/bootstrap/cache/config.php'),'statuses'=>$statuses,'csrf'=>'419','grants'=>'restricted browse allowed; mutation denied','sessions'=>count(glob($root.'/storage/framework/sessions/*'))];file_put_contents('/rehearsal/'.$phase.'-behavior.json',json_encode($out,JSON_PRETTY_PRINT));echo json_encode($out);exit;
}
if ($action==='export') {
    $app['auth']->guard('admin')->setUser(Encore\Admin\Auth\Database\Administrator::first());
    $request=Illuminate\Http\Request::create('http://localhost/admin/records?_export_=all');$app->instance('request',$request);Illuminate\Support\Facades\Request::swap($request);
    $grid=new Encore\Admin\Grid(new App\Admin\Record());$grid->column('id');$grid->column('name');$grid->render();exit(3);
}
throw new RuntimeException('Unknown action');

} catch (Throwable $e) { fwrite(STDERR,(string)$e."\n");exit(1); }
