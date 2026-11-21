<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class WorkflowIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    if (($argv[2]??'')==='frozen') {
        require '/application/.scratch/php85-laravel13-upgrade/issue17-cycle1-candidate/src/Widgets/StepForm.php';
        require '/application/.scratch/php85-laravel13-upgrade/issue17-cycle1-candidate/src/Widgets/MultipleSteps.php';
    }
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>false,'session.serialization'=>($argv[1]??'php'),'session.driver'=>'file','session.cookie'=>'workflows_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>WorkflowIdentityFixture::class],
        'admin.database.users_model'=>WorkflowIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=WorkflowIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
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
    abstract class WorkflowForm extends Encore\Admin\Widgets\StepForm {
        public function form() {$this->text($this->current==='one'?'value':'approved')->rules('required');}
        public function handle(Illuminate\Http\Request $request) {
            if ($this->current==='one') return $this->next($request->only('value'));
            session()->put('completed_'.static::FLOW,$this->all());$this->clear();return redirect('/nested/control/same');
        }
    }
    class WorkflowAFirst extends WorkflowForm {const FLOW='a';}
    class WorkflowALast extends WorkflowForm {const FLOW='a';}
    class WorkflowBFirst extends WorkflowForm {const FLOW='b';}
    class WorkflowBLast extends WorkflowForm {const FLOW='b';}
    @mkdir(admin_path(),0777,true);file_put_contents(admin_path('bootstrap.php'),'<?php');
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Bootstrap::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('same',static function(){
            return (new Encore\Admin\Layout\Content())->title('Same-page workflows')
                ->body(new Encore\Admin\Widgets\MultipleSteps(['one'=>WorkflowAFirst::class,'two'=>WorkflowALast::class]))
                ->body(new Encore\Admin\Widgets\MultipleSteps(['one'=>WorkflowBFirst::class,'two'=>WorkflowBLast::class]));
        });
        $router->post('_handle_form_',[Encore\Admin\Controllers\HandleController::class,'handleForm'])->name('admin.handle-form');
    });
    $router->getRoutes()->refreshNameLookups();
    function formData($html,$class){
        $crawler=new Symfony\Component\DomCrawler\Crawler($html);$data=null;
        $crawler->filter('form')->each(function($form)use($class,&$data){
            $input=$form->filter('input[name="_form_"]');if (!$input->count()||$input->attr('value')!==$class)return;
            $data=[];$form->filter('input[name]')->each(function($node)use(&$data){$data[$node->attr('name')]=$node->attr('value')??'';});
        });ensure(is_array($data),'Missing actual widget form '.$class);return $data;
    }
    $page=$request('GET','/same');ensure($page->getStatusCode()===200,'Same-page render');
    $a=formData($page->getContent(),WorkflowAFirst::class);$b=formData($page->getContent(),WorkflowBFirst::class);
    $a['value']='Approved A';$a['_token']=$csrf;$b['value']='Approved B';$b['_token']=$csrf;
    ensure($request('POST','/_handle_form_',$a)->getStatusCode()===302,'Save A');
    ensure($request('POST','/_handle_form_',$b)->getStatusCode()===302,'Save B');
    $page=$request('GET','/same');$aRead=formData($page->getContent(),WorkflowAFirst::class);$bRead=formData($page->getContent(),WorkflowBFirst::class);
    if (($argv[2]??'')==='frozen') {
        ensure($aRead['value']==='Approved B'&&$bRead['value']==='Approved B','Original overwrite was not reproduced');
        $last=$request('GET','/same?step=two');$finish=formData($last->getContent(),WorkflowALast::class);$finish['approved']='yes';$finish['_token']=$csrf;
        ensure($request('POST','/_handle_form_',$finish)->getStatusCode()===302,'Original A completion');
        $page=$request('GET','/same');$remaining=formData($page->getContent(),WorkflowBFirst::class);
        ensure(($remaining['value']??'')==='','Original completion did not wipe B');
        ensure(!isset(session('admin_step_contexts',[])[$b['_step_context']]),'Original B context survived unexpectedly');
        echo json_encode(['status'=>'reproduced','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'serialization'=>config('session.serialization'),'observed'=>['a_reads_b_data'=>true,'a_completion_wipes_b_data_and_context'=>true],'captured_sha256'=>hash_file('sha256','/application/.scratch/php85-laravel13-upgrade/issue17-cycle1-candidate/src/Widgets/StepForm.php')],JSON_PRETTY_PRINT).PHP_EOL;exit(0);
    }
    ensure($aRead['value']==='Approved A'&&$bRead['value']==='Approved B','Same-page workflows overwrite one another');
    $aContext=session('admin_step_contexts.'.$a['_step_context']);$bContext=session('admin_step_contexts.'.$b['_step_context']);
    ensure($aContext['stateKey']!==$bContext['stateKey'],'Workflow definitions share a state key');
    session()->put('host-owned','keep');session()->save();
    $last=$request('GET','/same?step=two');$aLast=formData($last->getContent(),WorkflowALast::class);$bLast=formData($last->getContent(),WorkflowBLast::class);$aLast['_token']=$csrf;$bLast['_token']=$csrf;
    $failed=$request('POST','/_handle_form_',$aLast,['Referer'=>'https://admin.example.test/nested/control/same?step=two']);ensure($failed->getStatusCode()===302&&session('errors')!==null,'A failure feedback');
    $correction=$request('GET','/same?step=two');ensure(str_contains($correction->getContent(),'The Approved field is required.'),'Failed step feedback render');
    $page=$request('GET','/same');ensure(formData($page->getContent(),WorkflowAFirst::class)['value']==='Approved A','A failure lost A data');ensure(formData($page->getContent(),WorkflowBFirst::class)['value']==='Approved B','A failure lost B data');
    $aLast['approved']='yes';$aLast['_workflow']='forged';
    ensure($request('POST','/_handle_form_',$aLast)->getStatusCode()===302,'A completion');
    ensure(session('completed_a.one.value')==='Approved A','Cross-step A state lost');
    ensure(!isset(session('admin_step_contexts',[])[$a['_step_context']]),'A context retained');
    ensure(isset(session('admin_step_contexts',[])[$b['_step_context']]),'A completion cleared B context');
    $page=$request('GET','/same');ensure(formData($page->getContent(),WorkflowAFirst::class)['value']==='','A own state retained');ensure(formData($page->getContent(),WorkflowBFirst::class)['value']==='Approved B','A completion cleared B state');
    $bLast['approved']='yes';ensure($request('POST','/_handle_form_',$bLast)->getStatusCode()===302,'Previously rendered B context unusable');
    ensure(session('completed_b.one.value')==='Approved B','Cross-step B state lost');
    $page=$request('GET','/same');ensure(formData($page->getContent(),WorkflowBFirst::class)['value']==='','B own state retained');ensure(session('host-owned')==='keep','Host data cleared');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'serialization'=>config('session.serialization'),'checks'=>['same_page_same_keys_different_classes_independent_state','stable_cross_step_workflow_identity','failed_completion_retains_both_approved_states_and_feedback','completion_clears_only_own_state_and_all_own_contexts','other_workflow_existing_context_remains_usable','client_workflow_input_does_not_select_state','host_session_preserved']],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
