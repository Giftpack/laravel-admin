<?php
/** Real challenge HTTP route, cookie session, validation and request-forgery middleware. */
try {
    require '/host/vendor/autoload.php';require '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition,$message){if(!$condition)throw new RuntimeException($message);}
    ensure(Composer\InstalledVersions::isInstalled('mews/captcha'),'Captcha fixture missing');
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'captcha_session','admin.route.prefix'=>'admin','admin.operation_log.enable'=>false,
        'captcha.characters'=>['a'],'captcha.default.length'=>5,'captcha.default.width'=>120,'captcha.default.height'=>36,'captcha.default.expire'=>5,'captcha.default.bgImage'=>false,'captcha.default.math'=>false]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $role=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=Encore\Admin\Auth\Database\Administrator::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);$actor->roles()->attach($role);
    @mkdir(admin_path(),0777,true);file_put_contents(admin_path('bootstrap.php'),'<?php');
    $build=function(){return new Encore\Admin\Form(new Encore\Admin\Auth\Database\Administrator(),function($form){$form->text('username')->rules('required');$form->text('name')->rules('required');$form->password('password');$form->captcha('ignored');});};
    $router=$app['router'];$router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Bootstrap::class])->prefix('admin')->group(function()use($router,$build){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');$router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->get('captcha-form',fn()=>(new Encore\Admin\Layout\Content())->body($build()));
        $router->post('captcha-store',fn()=>$build()->store());
    });$router->getRoutes()->refreshNameLookups();$cookies=[];
    $request=function($method,$path,$data=[])use($app,&$cookies){
        $app['auth']->forgetGuards();$req=Illuminate\Http\Request::create('http://localhost'.$path,$method,$data,$cookies,[],['HTTP_HOST'=>'localhost','HTTP_SEC_FETCH_SITE'=>'cross-site','HTTP_REFERER'=>'http://localhost/admin/captcha-form']);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);foreach($res->headers->getCookies()as$cookie)$cookies[$cookie->getName()]=$cookie->getValue();return$res;
    };
    $app->instance('env','captcha-acceptance');ensure(!$app->runningUnitTests(),'CSRF bypass');
    $request('GET','/admin/auth/login');ensure($request('POST','/admin/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>session()->token()])->getStatusCode()===302,'Login');
    $csrf=session()->token();$page=$request('GET','/admin/captcha-form');ensure($page->getStatusCode()===200,'Form rendering');
    $crawler=new Symfony\Component\DomCrawler\Crawler($page->getContent());$src=$crawler->filter('img#__captcha__-captcha')->attr('src');
    ensure(str_contains($page->getContent(),"split('?')[0]"),'Refresh asset script');
    $challenge=$request('GET',parse_url($src,PHP_URL_PATH).'?first');ensure($challenge->getStatusCode()===200&&$challenge->headers->get('Content-Type')==='image/jpeg','Actual challenge route');
    ensure(getimagesizefromstring($challenge->getContent())[0]===120,'Actual JPEG challenge dimensions');$first=session('captcha.key');
    $refresh=$request('GET',parse_url($src,PHP_URL_PATH).'?refresh');ensure($refresh->getStatusCode()===200&&session('captcha.key')!==$first,'Actual challenge refresh session');
    $base=['username'=>'native-captcha-user','name'=>'Native captcha','password'=>'fixture','__captcha__'=>'aaaaa'];
    ensure($request('POST','/admin/captcha-store',$base)->getStatusCode()===419,'Captcha CSRF');
    $bad=$base+['_token'=>$csrf];$bad['__captcha__']='incorrect';$response=$request('POST','/admin/captcha-store',$bad);ensure($response->getStatusCode()===302&&session('errors')!==null,'Wrong captcha validation');
    ensure(!Encore\Admin\Auth\Database\Administrator::where('username',$base['username'])->exists(),'Wrong captcha wrote row');
    $request('GET','/captcha/default');
    try{Carbon\Carbon::setTestNow(now()->addSeconds(10));$request('POST','/admin/captcha-store',$base+['_token'=>$csrf]);ensure(!Encore\Admin\Auth\Database\Administrator::where('username',$base['username'])->exists(),'Expired captcha wrote row');ensure(!session()->has('captcha'),'Expired challenge not removed');}
    finally{Carbon\Carbon::setTestNow();}
    $request('GET','/captcha/default');$response=$request('POST','/admin/captcha-store',$base+['_token'=>$csrf]);ensure($response->getStatusCode()===302,'Correct captcha response');
    ensure(Encore\Admin\Auth\Database\Administrator::where('username',$base['username'])->count()===1,'Correct captcha failed persistence');ensure(!session()->has('captcha'),'Correct challenge not consumed');
    $base['username']='replayed-captcha-user';$request('POST','/admin/captcha-store',$base+['_token'=>$csrf]);ensure(!Encore\Admin\Auth\Database\Administrator::where('username',$base['username'])->exists(),'Replayed challenge accepted');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'captcha'=>Composer\InstalledVersions::getPrettyVersion('mews/captcha'),'intervention'=>Composer\InstalledVersions::getPrettyVersion('intervention/image'),'checks'=>['actual_field_html_refresh_script','native_jpeg_challenge_cookie_session','native_refresh_replaces_hash','csrf419','incorrect_no_write','real_cache_expiry_no_write','correct_persistence_captcha_ignored','consumed_replay_no_write']],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
