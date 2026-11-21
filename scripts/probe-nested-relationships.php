<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class NestedIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'nested_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>NestedIdentityFixture::class],
        'admin.database.users_model'=>NestedIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=NestedIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    require_once '/application/tests/Fixtures/NestedRelationshipModels.php';
    class NativeNestedTag extends NestedTag { protected $connection='forms'; }
    class NativeNestedTagPicker extends NestedTagPicker { public $model=NativeNestedTag::class; }
    $schema=$app['db']->connection('forms')->getSchemaBuilder();
    $schema->create('nested_tags',function($t) { $t->string('tag_code')->primary(); $t->string('label'); $t->boolean('visible')->default(true); });
    $schema->create('nested_parents',function($t) { $t->string('code')->primary(); $t->string('title')->nullable(); $t->string('owner_code')->nullable(); $t->json('settings')->nullable(); $t->json('rows')->nullable(); });
    $schema->create('nested_children',function($t) { $t->string('child_code')->primary(); $t->string('parent_code')->nullable(); $t->string('label'); $t->integer('position')->default(0); $t->string('locked')->nullable(); $t->json('meta')->nullable(); });
    $schema->create('nested_notes',function($t) { $t->increments('id'); $t->string('subject_id')->nullable(); $t->string('subject_type')->nullable(); $t->string('label'); $t->integer('position')->default(0); });
    $schema->create('nested_pivots',function($t) { $t->string('parent_code'); $t->string('tag_code'); $t->integer('weight')->default(0); $t->unique(['parent_code','tag_code']); });
    NestedTag::insert([['tag_code'=>'t0','label'=>'Visible','visible'=>true],['tag_code'=>'hidden','label'=>'Restricted','visible'=>false]]);
    $make=function() {
        $f=new Encore\Admin\Form((new NestedParent())->setConnection('forms'));
        $f->hidden('code'); $f->text('title');
        $f->belongsTo('owner_code',NativeNestedTagPicker::class);
        $f->belongsToMany('tags',NativeNestedTagPicker::class);
        $f->table('rows',function($n) { $n->text('label')->rules('required'); $n->multipleSelect('values')->options(['a'=>'A','b'=>'B']); });
        $f->embeds('settings',fn($e)=>$e->text('name')->rules('required'));
        $f->hasMany('children',function($n) { $n->hidden('child_code'); $n->text('label')->rules('required'); $n->number('position'); });
        $f->morphMany('notes',fn($n)=>$n->text('label')->rules('required'));
        return $f;
    };
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router,$make){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->get('records/create',fn()=>$make()->render());
        $router->post('records',fn()=>$make()->store());
        $router->put('records/{key}',fn($key)=>$make()->update($key));
        $router->get('records/{key}/edit',fn($key)=>$make()->edit($key)->render());
        $router->get('_handle_selectable_',[Encore\Admin\Controllers\HandleController::class,'handleSelectable'])->name('admin.handle-selectable');
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
    $app->instance('env','nested-acceptance'); ensure(!$app->runningUnitTests(),'CSRF bypass active');
    $request('GET','/auth/login'); $csrf=session()->token();
    ensure($request('POST','/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Login');
    $csrf=session()->token(); $ajax=['X-Requested-With'=>'XMLHttpRequest'];
    $create=$request('GET','/records/create'); ensure($create->getStatusCode()===200,'Create render '.$create->getStatusCode());
    ensure(str_contains($create->getContent(),'name="_token"'),'CSRF field');
    $data=['code'=>'p','title'=>'Before','owner_code'=>'t0','tags'=>['t0'],'rows'=>['new_3'=>['label'=>'Row','values'=>['a','b']]],'settings'=>['name'=>'JSON'],'children'=>['new_4'=>['child_code'=>'c','label'=>'Child','position'=>0]],'notes'=>['new_5'=>['label'=>'Morph']]];
    foreach (['POST','PUT'] as $method) ensure($request($method,'/records'.($method==='PUT'?'/p':''),$data,$ajax)->getStatusCode()===419,'CSRF '.$method);
    $res=$request('POST','/records',$data+['_token'=>$csrf],$ajax); ensure(json_decode($res->getContent(),true)['status']===true,'Create '.$res->getContent());
    $p=(new NestedParent())->setConnection('forms')->findOrFail('p'); ensure($p->getConnectionName()==='forms','Configured connection');
    ensure($p->children->first()->parent_code==='p'&&$p->notes->first()->subject_id==='p','Relation identities');
    ensure($p->settings===['name'=>'JSON']&&$p->rows===['new_3'=>['label'=>'Row','values'=>['a','b']]],'JSON roundtrip');
    $edit=$request('GET','/records/p/edit'); ensure($edit->getStatusCode()===200&&str_contains($edit->getContent(),'Child'),'Edit reload');
    $res=$request('PUT','/records/p',['_token'=>$csrf,'owner_code'=>'hidden','title'=>'Rejected'],$ajax); ensure(json_decode($res->getContent(),true)['status']===false&&$p->fresh()->title==='Before','Hidden picker rejected');
    $res=$request('PUT','/records/p',['_token'=>$csrf,'title'=>'Rejected missing child','children'=>['new_required'=>['child_code'=>'missing-label']]],$ajax);
    ensure(json_decode($res->getContent(),true)['status']===false&&$p->fresh()->title==='Before'&&$p->fresh()->children->count()===1,'New child required fields on existing parent');
    NestedChild::saved(function($child) { if($child->label==='Fail') throw new RuntimeException('synthetic related failure'); });
    $res=$request('PUT','/records/p',['_token'=>$csrf,'title'=>'Rollback','tags'=>[],'children'=>['c'=>['child_code'=>'c','label'=>'Fail']]],$ajax);
    NestedChild::flushEventListeners(); ensure($res->getStatusCode()===500,'Failure response'); ensure($p->fresh()->title==='Before'&&$p->fresh()->children->first()->label==='Child'&&$p->fresh()->tags->modelKeys()===['t0'],'Atomic related rollback');
    $res=$request('PUT','/records/p',['_token'=>$csrf,'title'=>'After','children'=>['c'=>['position'=>4]],'rows'=>['new_3'=>['values'=>[]]]],$ajax); ensure(json_decode($res->getContent(),true)['status']===true,'Update');
    ensure($p->fresh()->settings===['name'=>'JSON']&&$p->fresh()->notes->count()===1,'Omitted collections preserved');
    ensure($p->fresh()->children->count()===1&&$p->fresh()->children->first()->label==='Child'&&$p->fresh()->children->first()->position===4,'Missing hidden key uses existing scoped child');
    ensure($p->fresh()->rows===['new_3'=>['label'=>'Row','values'=>[]]],'Explicit array replacement preserves omitted label');
    $res=$request('PUT','/records/p',['_token'=>$csrf,'children'=>['c'=>['child_code'=>'c','_remove_'=>1]],'tags'=>[]],$ajax); ensure(json_decode($res->getContent(),true)['status']===true,'Remove');
    ensure($p->fresh()->children->isEmpty()&&$p->fresh()->tags->isEmpty()&&NestedTag::count()===2,'Child deletion / pivot detach');
    $last=$request('GET','/records/p/edit'); ensure($last->getStatusCode()===200&&str_contains($last->getContent(),'After'),'Final edit');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'vendor'=>realpath('/host/vendor'),'csrf_bypass'=>false,'checks'=>['custom_guard_domain_connection','nested_create_update_remove','new_child_required_on_update','missing_child_key_identity','table_array_replacement','json_roundtrip','ownership','selector_visibility','related_failure_atomicity','omitted_children','reload']],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) { fwrite(STDERR,(string)$exception.PHP_EOL); exit(1); }
