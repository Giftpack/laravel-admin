<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class DetailIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>false,'session.serialization'=>($argv[1]??'php'),'session.driver'=>'file','session.cookie'=>'details_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>DetailIdentityFixture::class],
        'admin.database.users_model'=>DetailIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=DetailIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);

    });
    $router->getRoutes()->refreshNameLookups();
    $cookies=[];
    $request=function($method,$path,$data=[],$headers=[])use($app,&$cookies){
        $app['auth']->forgetGuards();
        $server=['HTTP_HOST'=>'admin.example.test','HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_SEC_FETCH_SITE'=>'cross-site'];
        foreach ($headers as $key=>$value) $server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;
        $req=Illuminate\Http\Request::create('https://admin.example.test/nested/control'.$path,$method,$data,$cookies,[],$server);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);
        foreach ($res->headers->getCookies() as $cookie) $cookies[$cookie->getName()]=$cookie->getValue();
        ensure(config('auth.defaults.guard')==='web','Default guard leaked');
        return $res;
    };
    $app->instance('env','forms-acceptance'); ensure(!$app->runningUnitTests(),'CSRF bypass active');
    $request('GET','/auth/login'); $csrf=session()->token();
    ensure($request('POST','/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Login');
    $csrf=session()->token();
    class NativeFirstStep extends Encore\Admin\Widgets\StepForm {
        public function form() {ensure($this->current==='first','First validation did not restore approved step');$this->text('name')->rules('required');}
        public function handle(Illuminate\Http\Request $request) {return $this->next($request->only('name'));}
    }
    class NativeLastStep extends Encore\Admin\Widgets\StepForm {
        public function form() {ensure($this->current==='last','Last validation did not restore approved step');$this->text('approved')->rules('required');}
        public function handle(Illuminate\Http\Request $request) {
            $all=$this->all(); session()->put('completed_result',$all);$this->clear();return redirect('/nested/control/done');
        }
    }
    @mkdir(admin_path(),0777,true);file_put_contents(admin_path('bootstrap.php'),'<?php');
    Encore\Admin\Admin::script('host-global-script');
    Encore\Admin\Admin::booted(static function(){Encore\Admin\Admin::script('persistent-extension-callback');});
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Bootstrap::class,Encore\Admin\Middleware\Pjax::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('wizard',static function(){Encore\Admin\Admin::script('wizard-only-script');return (new Encore\Admin\Layout\Content())->title('中文 wizard')->body(new Encore\Admin\Widgets\MultipleSteps(['first'=>NativeFirstStep::class,'last'=>NativeLastStep::class]));});
        $router->get('other',static fn()=>(new Encore\Admin\Layout\Content())->title('Other')->body(new Encore\Admin\Widgets\MultipleSteps(['first'=>NativeFirstStep::class,'last'=>NativeLastStep::class])));
        $router->post('_handle_form_',[Encore\Admin\Controllers\HandleController::class,'handleForm'])->name('admin.handle-form');
        $router->get('failed',static fn()=>response('Controlled failure',503));
        $router->get('typed',static fn()=>throw new TypeError('host-private-marker'));
        $router->get('ordinary',static fn()=>abort(418,'host-status-marker'));
        $router->get('json',static fn()=>response()->json(['ok'=>true]));
    });
    $router->getRoutes()->refreshNameLookups();
    function fields($html){$crawler=new Symfony\Component\DomCrawler\Crawler($html);$out=[];$crawler->filter('input[name]')->each(function($node)use(&$out){$out[$node->attr('name')]=$node->attr('value')??'';});return $out;}
    $first=$request('GET','/wizard');ensure($first->getStatusCode()===200,'Wizard render '.$first->getStatusCode().' '.substr(strip_tags($first->getContent()),0,300));$data=fields($first->getContent());
    ensure(isset($data['_context'],$data['_step_context']), 'Actual server contexts missing');
    ensure(str_contains($first->getContent(),'persistent-extension-callback'),'Boot callback absent');
    $data['name']='Retained approved';$data['_token']=$csrf;
    ensure($request('POST','/_handle_form_',array_diff_key($data,['_token'=>true]))->getStatusCode()===419,'Step CSRF bypass');
    $invalid=$data;$invalid['name']='';ensure($request('POST','/_handle_form_',$invalid,['Referer'=>'https://admin.example.test/nested/control/wizard'])->getStatusCode()===302,'Validation response');
    ensure(session('errors')!==null,'Invalid form did not flash errors '.json_encode($invalid));
    $correction=$request('GET','/wizard');ensure(str_contains($correction->getContent(),'The Name field is required.'),'Validation feedback lost');
    $data['_url']='https://evil.example';$data['_current']='forged';$data['_steps']='forged';
    $next=$request('POST','/_handle_form_',$data);ensure($next->getStatusCode()===302&&$next->headers->get('Location')==='https://admin.example.test/nested/control/wizard?step=last','Server-approved progression');
    $return=$request('GET','/wizard');ensure(str_contains($return->getContent(),'Retained approved'),'Earlier step data not retained');
    session()->put('host-owned','preserved');session()->put('steps.host','preserved');session()->save();
    $other=$request('GET','/other');ensure(!str_contains($other->getContent(),'wizard-only-script'),'Request scripts leaked');ensure(str_contains($other->getContent(),'persistent-extension-callback'),'Persistent registration disappeared');
    $otherData=fields($other->getContent());$otherData['name']='Independent';$otherData['_token']=$csrf;
    ensure($request('POST','/_handle_form_',$otherData)->getStatusCode()===302,'Independent wizard save');
    $last=$request('GET','/wizard?step=last');$lastData=fields($last->getContent());$lastData['_token']=$csrf;
    ensure($request('POST','/_handle_form_',$lastData)->getStatusCode()===302&&session('errors')!==null,'Final validation feedback');
    ensure(str_contains($request('GET','/wizard')->getContent(),'Retained approved'),'Final failure lost earlier approved state');
    $lastData['approved']='yes';
    ensure($request('POST','/_handle_form_',$lastData)->headers->get('Location')==='https://admin.example.test/nested/control/done','Completion');
    ensure(session('completed_result.first.name')==='Retained approved','Completion lost approved state');
    ensure(session('host-owned')==='preserved'&&session('steps.host')==='preserved','Completion removed host state');
    ensure(str_contains($request('GET','/other')->getContent(),'Independent'),'Completion removed independent form state');
    ensure(!str_contains($request('GET','/wizard')->getContent(),'Retained approved'),'Completion retained owned form state');
    $ajax=['X-PJAX'=>'true','X-PJAX-CONTAINER'=>'#pjax-container'];
    $pjax=$request('GET','/wizard',[],$ajax);ensure($pjax->getStatusCode()===200&&str_contains($pjax->getContent(),'中文 wizard')&&!str_contains($pjax->getContent(),'<html'),'Native PJAX extraction');
    ensure($pjax->headers->get('X-PJAX-URL')==='/nested/control/wizard','Native URL header');
    ensure($request('GET','/failed',[],$ajax)->getStatusCode()===503,'Failed response status changed');
    $typed=$request('GET','/typed',[],$ajax);ensure($typed->getStatusCode()===500&&!str_contains($typed->getContent(),'host-private-marker'),'Host Throwable handling changed');
    ensure($request('GET','/ordinary',[],$ajax)->getStatusCode()===418,'Host HTTP exception handling changed');
    ensure(json_decode($request('GET','/json',[],$ajax)->getContent(),true)===['ok'=>true],'Native JSON transport changed');
    ensure(Encore\Admin\Admin::$script===['host-global-script'],'Native presentation state leaked');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'serialization'=>config('session.serialization'),'checks'=>['native_cookie_csrf419','signed_widget_context','step_validation_feedback','approved_progression_tamper_ignored','retained_prior_data','owned_cleanup_host_and_other_wizard_preserved','completion_redirect','native_unicode_pjax_title_url','failed503_without_exception','host_typeerror500_redacted','host_http418','native_json','same_process_request_assets_callback_registration']],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
