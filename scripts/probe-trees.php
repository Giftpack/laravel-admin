<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class TreeIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'trees_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>TreeIdentityFixture::class],
        'admin.database.users_model'=>TreeIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=TreeIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    class GenericTreeController extends Illuminate\Routing\Controller {
        use Encore\Admin\Controllers\HasResourceActions;
        public function form() {
            $form=new Encore\Admin\Form(new Encore\Admin\Auth\Database\Menu());
            $form->text('title')->rules('required');
            return $form;
        }
    }
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->resource('auth/menu',Encore\Admin\Controllers\MenuController::class);
        $router->post('generic-tree',[GenericTreeController::class,'store']);
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
    $ajax=['X-Requested-With'=>'XMLHttpRequest'];
    $menu=Encore\Admin\Auth\Database\Menu::class;
    $parent=$menu::create(['title'=>'Parent','icon'=>'fa-bars','order'=>0]);
    $child=$menu::create(['title'=>'Child','icon'=>'fa-bars','parent_id'=>$parent->id,'order'=>1]);
    $deep=$menu::create(['title'=>'Deep','icon'=>'fa-bars','parent_id'=>$child->id,'order'=>2]);
    foreach ([$parent,$child,$deep] as $node) $node->roles()->attach($adminRole);
    $order=json_encode([['id'=>$child->id,'children'=>[['id'=>$parent->id]]],['id'=>$deep->id]]);
    $before=$menu::orderBy('id')->get()->toArray();
    ensure($request('POST','/auth/menu',['_order'=>$order],$ajax)->getStatusCode()===419,'CSRF reorder');
    ensure($menu::orderBy('id')->get()->toArray()===$before,'CSRF changed hierarchy');
    $res=$request('POST','/auth/menu',['_token'=>$csrf,'_order'=>$order],$ajax);
    ensure($res->getStatusCode()===200&&json_decode($res->getContent(),true)['status']===true,'Actual menu reorder '.$res->getStatusCode().' '.substr(strip_tags($res->getContent()),0,400));
    ensure($parent->fresh()->parent_id===$child->id&&$child->fresh()->parent_id===0,'Reorder persisted');
    ensure($menu::count()===3,'Reorder created phantom record');
    $res=$request('GET','/auth/menu'); ensure($res->getStatusCode()===200&&str_contains($res->getContent(),'Parent'),'Tree controller render');
    $generic=$request('POST','/generic-tree',['_token'=>$csrf,'_order'=>$order],$ajax);
    ensure($generic->getStatusCode()===200&&json_decode($generic->getContent(),true)['status']===true&&$menu::count()===3,'Generic controller tree reorder');
    $reordered=$menu::orderBy('id')->get()->toArray();
    foreach (['null','{}',json_encode([['id'=>$parent->id],['id'=>999999]]),json_encode([['id'=>$child->id,'children'=>[['id'=>$child->id]]]])] as $bad) {
        $res=$request('POST','/auth/menu',['_token'=>$csrf,'_order'=>$bad],$ajax);
        ensure($res->getStatusCode()===422&&json_decode($res->getContent(),true)['status']===false,'Malformed reorder response '.$bad.' '.$res->getStatusCode());
        ensure($menu::orderBy('id')->get()->toArray()===$reordered,'Malformed reorder partial write');
    }
    // Restore initial hierarchy and verify actual controller deletion and pivot cleanup.
    $request('POST','/auth/menu',['_token'=>$csrf,'_order'=>json_encode([['id'=>$parent->id,'children'=>[['id'=>$child->id,'children'=>[['id'=>$deep->id]]]]]])],$ajax);
    ensure($request('DELETE','/auth/menu/'.$parent->id,[],$ajax)->getStatusCode()===419,'CSRF deletion');
    $res=$request('DELETE','/auth/menu/'.$parent->id,['_token'=>$csrf],$ajax);
    ensure(json_decode($res->getContent(),true)['status']===true,'Menu deletion '.$res->getContent());
    ensure(!$menu::find($parent->id)&&!$menu::find($child->id)&&$menu::find($deep->id),'Direct children deleted, deep retained');
    ensure(!Illuminate\Support\Facades\DB::connection('forms')->table('forms_role_menu')->whereIn('menu_id',[$parent->id,$child->id])->exists(),'Role pivots cleaned');
    $denied=TreeIdentityFixture::create(['username'=>'denied','name'=>'Denied','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $cookies=[]; $request('GET','/auth/login'); $csrf=session()->token();
    ensure($request('POST','/auth/login',['username'=>'denied','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Denied actor login');
    $csrf=session()->token(); $before=$menu::orderBy('id')->get()->toArray();
    $res=$request('POST','/auth/menu',['_token'=>$csrf,'_order'=>json_encode([['id'=>$deep->id]])],$ajax);
    ensure($res->getStatusCode()===403,'Permission denial '.$res->getStatusCode());
    ensure($menu::orderBy('id')->get()->toArray()===$before,'Denied actor changed hierarchy');
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'csrf_bypass'=>false,'checks'=>['actual_menu_controller_reorder','generic_HasResourceActions_Form_reorder','no_phantom_create','controller_render','malformed_missing_self_reference_422_atomic','csrf_reorder_and_delete_419','role_denial_403','direct_child_delete_deep_retained_pivots_cleaned','custom_guard_domain_connection']],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) { fwrite(STDERR,(string)$exception.PHP_EOL); exit(1); }
