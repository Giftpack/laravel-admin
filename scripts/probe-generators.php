<?php
/** Actual generated operations through native protected endpoints; no handler/context substitutes. */
try {
    require '/host/vendor/autoload.php';
    require '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition,$message) {if (!$condition) throw new RuntimeException($message);}
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'generators_session','admin.route.prefix'=>'admin','admin.operation_log.enable'=>false]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=Encore\Admin\Auth\Database\Administrator::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    $denied=Encore\Admin\Auth\Database\Administrator::create(['username'=>'denied','name'=>'Denied','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    @mkdir(admin_path(),0777,true); file_put_contents(admin_path('bootstrap.php'),'<?php');
    class NativeGeneratedFinalStep extends Encore\Admin\Widgets\StepForm {
        public function form() {$this->text('approved')->rules('required');}
        public function handle(Illuminate\Http\Request $request) {session()->put('generated-completion',$this->all());$this->clear();return redirect('/admin/completed');}
    }
    $classes=[];
    foreach (['generic'=>[], 'row'=>['--grid-row'=>true], 'batch'=>['--grid-batch'=>true]] as $type=>$typeArgs) {
        foreach (['plain'=>[], 'form'=>['--form'=>true], 'dialog'=>['--dialog'=>true]] as $mode=>$modeArgs) {
            $name=ucfirst($type).ucfirst($mode);$default=$type==='generic'&&$mode==='plain';
            $class=($default?'App\\Admin\\Actions':'App\\Admin\\Generated').'\\Nested\\'.$name;
            $namespace=$default?[]:['--namespace'=>'App\\Admin\\Generated'];
            ensure(Illuminate\Support\Facades\Artisan::call('admin:action',['name'=>'Nested\\'.$name,'--name'=>"User's \$label <b>" ]+$namespace+$typeArgs+$modeArgs)===0,'Generate '.$class);
            ensure(class_exists($class),'Autoload '.$class);$classes[$class]=[$type,$mode];
        }
    }
    foreach (['ordinary'=>[],'step'=>['--step'=>true]] as $type=>$options) {
        $class=($type==='ordinary'?'App\\Admin\\Forms':'App\\Admin\\Generated').'\\Nested\\'.ucfirst($type).'Form';
        $namespace=$type==='ordinary'?[]:['--namespace'=>'App\\Admin\\Generated'];
        ensure(Illuminate\Support\Facades\Artisan::call('admin:form',['name'=>'Nested\\'.ucfirst($type).'Form','--title'=>"User's \$title"]+$namespace+$options)===0,'Generate '.$class);
        ensure(class_exists($class),'Autoload '.$class);$classes[$class]=['widget',$type];
    }
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class])->prefix('admin')->group(function()use($router,$classes,$actor){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->post('_handle_action_',[Encore\Admin\Controllers\HandleController::class,'handleAction'])->name('admin.handle-action');
        $router->post('_handle_form_',[Encore\Admin\Controllers\HandleController::class,'handleForm'])->name('admin.handle-form');
        $router->get('producer/{index}',function($index)use($classes,$actor){
            $class=array_keys($classes)[$index];[$type,$mode]=$classes[$class];$object=new $class();
            if ($type==='widget') {
                if ($mode==='step') $object=(new Encore\Admin\Widgets\MultipleSteps(['generated'=>$class,'complete'=>NativeGeneratedFinalStep::class]));
                return (new Encore\Admin\Layout\Content())->body($object)->render();
            }
            if ($type!=='generic') $object->setGrid(new Encore\Admin\Grid(new Encore\Admin\Auth\Database\Administrator()));
            if ($type==='row') $object->setRow($actor);
            // Render the actual action/interactor, and use its own public server context producer.
            $html=(string)$object->render();
            return response()->json(['html'=>$html,'route'=>$object->getHandleRoute(),'parameters'=>$object->parameters(),'action'=>$object->getCalledClass()]);
        });
    });
    $router->getRoutes()->refreshNameLookups();$cookies=[];
    $request=function($method,$path,$data=[],$headers=[])use($app,&$cookies){
        $app['auth']->forgetGuards();$server=['HTTP_HOST'=>'localhost','HTTP_SEC_FETCH_SITE'=>'cross-site'];
        foreach ($headers as $key=>$value) $server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;
        $req=Illuminate\Http\Request::create('http://localhost'.$path,$method,$data,$cookies,[],$server);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);
        foreach($res->headers->getCookies() as $cookie)$cookies[$cookie->getName()]=$cookie->getValue();
        return $res;
    };
    $app->instance('env','generator-acceptance');ensure(!$app->runningUnitTests(),'CSRF bypass active');
    $request('GET','/admin/auth/login');ensure($request('POST','/admin/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>session()->token()])->getStatusCode()===302,'Login');
    $csrf=session()->token();$checks=[];
    foreach ($classes as $class=>[$type,$mode]) {
        $index=count($checks); $producer=$request('GET','/admin/producer/'.$index);
        ensure($producer->getStatusCode()===200,'Producer '.$class.' '.substr(strip_tags($producer->getContent()),0,500));
        if ($type==='widget') {
            $crawler=new Symfony\Component\DomCrawler\Crawler($producer->getContent());$data=[];
            $crawler->filter('input[name]')->each(function($node)use(&$data){$data[$node->attr('name')]=$node->attr('value')??'';});
            ensure(isset($data['_context']),'Widget context');$path='/admin/_handle_form_';
        } else {
            $produced=json_decode($producer->getContent(),true,512,JSON_THROW_ON_ERROR);parse_str(parse_url($produced['route'],PHP_URL_QUERY),$data);
            $data+= $produced['parameters'];$data['_action']=$produced['action'];
            if ($type==='row')$data['_key']=$actor->id;
            if ($type==='batch')$data['_key']=[$actor->id,$denied->id];
            $path='/admin/_handle_action_';
        }
        unset($data['_token']);
        ensure($request('POST',$path,$data)->getStatusCode()===419,'CSRF '.$class);
        $data['_token']=$csrf;$missing=$data;unset($missing['_context']);
        ensure($request('POST',$path,$missing)->getStatusCode()===403,'Unsigned '.$class);
        if ($mode==='form'||$type==='widget') {
            $invalid=$data;$invalid['name']='';$invalid['email']='invalid';
            $response=$request('POST',$path,$invalid,['Referer'=>'http://localhost/admin/producer/'.$index]);
            if ($type==='widget') ensure($response->getStatusCode()===302&&session('errors')!==null,'Widget validation '.$class);
            else ensure(json_decode($response->getContent(),true)['status']===false,'Action validation '.$class);
        }
        $data['name']='Generated consumer';$data['email']='consumer@example.test';$data['created_at']='2026-11-21 12:30:00';
        $response=$request('POST',$path,$data,['Referer'=>'http://localhost/admin/producer/'.$index]);
        if ($type==='widget') {
            ensure($response->getStatusCode()===302,'Widget handle '.$class.' status '.$response->getStatusCode());
            if ($mode==='step') {
                ensure(str_ends_with($response->headers->get('Location'),'?step=complete'),'Generated step progression');
                $next=$request('GET','/admin/producer/'.$index.'?step=complete');$crawler=new Symfony\Component\DomCrawler\Crawler($next->getContent());$nextData=[];
                $crawler->filter('input[name]')->each(function($node)use(&$nextData){$nextData[$node->attr('name')]=$node->attr('value')??'';});
                $nextData['approved']='yes';$nextData['_token']=$csrf;
                ensure($request('POST',$path,$nextData)->getStatusCode()===302,'Generated workflow completion');
                ensure(session('generated-completion.generated.name')==='Generated consumer','Generated step retained data');
            }
        }
        else ensure($response->getStatusCode()===200&&json_decode($response->getContent(),true)['status']===true,'Action handle '.$class.' '.$response->getContent());
        $checks[$class]=['type'=>$type,'mode'=>$mode,'generated'=>true,'csrf419'=>true,'unsigned403'=>true,'handled'=>true];
    }
    // The same generated operation is denied for a signed nonadministrator without a configured ability.
    $cookies=[];$request('GET','/admin/auth/login');$request('POST','/admin/auth/login',['username'=>'denied','password'=>'fixture-password','_token'=>session()->token()]);
    $produced=json_decode($request('GET','/admin/producer/0')->getContent(),true);parse_str(parse_url($produced['route'],PHP_URL_QUERY),$data);
    $data+=['_action'=>$produced['action'],'_token'=>session()->token()];
    ensure($request('POST','/admin/_handle_action_',$data)->getStatusCode()===403,'Generated nonadministrator denial');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'generated_operations'=>$checks,'signed_nonadministrator_denied'=>true],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
