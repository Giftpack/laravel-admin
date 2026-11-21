<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class FormIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'forms_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>FormIdentityFixture::class],
        'admin.database.users_model'=>FormIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=FormIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->get('auth/setting',[Encore\Admin\Controllers\AuthController::class,'getSetting']);
        $router->put('auth/setting',[Encore\Admin\Controllers\AuthController::class,'putSetting']);
        $router->resource('auth/users',Encore\Admin\Controllers\UserController::class);
        $router->resource('auth/roles',Encore\Admin\Controllers\RoleController::class);
        $router->resource('auth/permissions',Encore\Admin\Controllers\PermissionController::class);
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
    foreach (['/auth/users/create','/auth/roles/create','/auth/permissions/create'] as $path) {
        $res=$request('GET',$path); ensure($res->getStatusCode()===200,'Form render '.$path.' '.$res->getStatusCode());
        ensure(str_contains($res->getContent(),'name="_token"'),'CSRF form token '.$path);
    }
    $create=$request('GET','/auth/users/create')->getContent();
    ensure(str_contains($create,'name="roles[]"')&&str_contains($create,'name="permissions[]"')&&str_contains($create,'type="file"'),'User grant/avatar fields');
    $data=['username'=>'created','name'=>'Created','password'=>'new-secret','password_confirmation'=>'new-secret'];
    foreach (['POST','PUT','DELETE'] as $method) ensure($request($method,'/auth/users'.($method==='POST'?'':'/'.$actor->id),$data)->getStatusCode()===419,'CSRF '.$method);
    $ajax=['X-Requested-With'=>'XMLHttpRequest'];
    foreach ([[],['username'=>'operator'],['username'=>['bad']],['password_confirmation'=>'different']] as $bad) {
        $invalid=array_replace($data,$bad,['_token'=>$csrf]); if ($bad===[]) unset($invalid['username']);
        $res=$request('POST','/auth/users',$invalid,$ajax);
        ensure($res->getStatusCode()===200 && json_decode($res->getContent(),true)['status']===false,'Invalid create '.json_encode($bad).' '.$res->getStatusCode().' '.substr($res->getContent(),0,1200));
        ensure(FormIdentityFixture::count()===1,'Invalid create wrote row');
    }
    ensure($request('POST','/auth/users',$data+['_token'=>$csrf],$ajax)->getStatusCode()===200,'Create');
    $target=FormIdentityFixture::where('username','created')->firstOrFail();
    ensure($target->getTable()==='forms_users'&&$target->getConnectionName()==='forms','Configured model/table/connection');
    ensure(Illuminate\Support\Facades\Hash::check('new-secret',$target->password),'Create hash'); $hash=$target->password;
    foreach (['/auth/users','/auth/users/'.$target->id,'/auth/users/'.$target->id.'/edit'] as $path) {
        $res=$request('GET',$path); ensure($res->getStatusCode()===200,'User read '.$path.' '.$res->getStatusCode());
        ensure(!str_contains($res->getContent(),$hash),'Password hash in page '.$path);
    }
    ensure($request('POST','/auth/roles',['_token'=>$csrf,'slug'=>'editor','name'=>'Editor','permissions'=>['']],$ajax)->getStatusCode()===200,'Role creation');
    $role=Encore\Admin\Auth\Database\Role::where('slug','editor')->firstOrFail();
    $permission=Encore\Admin\Auth\Database\Permission::create(['slug'=>'edit','name'=>'Edit']);
    $res=$request('PUT','/auth/users/'.$target->id,['_token'=>$csrf,'username'=>'created','name'=>'Renamed','password'=>'','password_confirmation'=>'','roles'=>[$role->id,''],'permissions'=>[$permission->id,'']],$ajax);
    ensure(json_decode($res->getContent(),true)['status']===true,'Edit response '.$res->getContent());
    ensure($target->fresh()->password===$hash && $target->fresh()->name==='Renamed','Unchanged hash/name');
    ensure($target->roles()->whereKey($role->id)->exists()&&$target->permissions()->whereKey($permission->id)->exists(),'User grants');
    $request('PUT','/auth/users/'.$target->id,['_token'=>$csrf,'password'=>'reset','password_confirmation'=>'reset','roles'=>[''],'permissions'=>['']],$ajax);
    ensure(Illuminate\Support\Facades\Hash::check('reset',$target->fresh()->password),'Reset hash');
    ensure(!$target->roles()->exists()&&!$target->permissions()->exists(),'Grant removal');
    $request('PUT','/auth/roles/'.$role->id,['_token'=>$csrf,'slug'=>'editor','name'=>'Editor','permissions'=>[$permission->id]],$ajax);
    ensure($role->permissions()->whereKey($permission->id)->exists(),'Role form assignment');
    $request('PUT','/auth/roles/'.$role->id,['_token'=>$csrf,'permissions'=>['']],$ajax);
    ensure(!$role->permissions()->exists(),'Role form removal');
    ensure($request('POST','/auth/permissions',['_token'=>$csrf,'slug'=>'created-grant','name'=>'Created grant','http_method'=>['GET'],'http_path'=>'/reports'],$ajax)->getStatusCode()===200,'Permission form creation');
    $createdGrant=Encore\Admin\Auth\Database\Permission::where('slug','created-grant')->firstOrFail();
    ensure($createdGrant->http_method===['GET']&&$createdGrant->http_path==='/reports','Permission form persistence');
    $request('PUT','/auth/permissions/'.$createdGrant->id,['_token'=>$csrf,'http_method'=>[''],'http_path'=>'/changed'],$ajax);
    ensure($createdGrant->fresh()->http_method===[]&&$createdGrant->fresh()->http_path==='/changed','Permission form edit');
    $profile=$request('GET','/auth/setting');
    ensure($profile->getStatusCode()===200&&!str_contains($profile->getContent(),$actor->password),'Profile render/hash');
    $before=$actor->password;
    $res=$request('PUT','/auth/setting',['_token'=>$csrf,'name'=>'Profile name','password'=>'','password_confirmation'=>'','username'=>'forged','roles'=>[''],'permissions'=>['']]);
    ensure($res->getStatusCode()===302&&str_ends_with($res->headers->get('Location'),'/nested/control/auth/setting'),'Profile redirect');
    ensure($actor->fresh()->name==='Profile name'&&$actor->fresh()->password===$before&&$actor->fresh()->username==='operator'&&$actor->roles()->whereKey($adminRole->id)->exists(),'Profile blank preserves');
    $res=$request('PUT','/auth/setting',['_token'=>$csrf,'name'=>'','password'=>'failed-secret','password_confirmation'=>'different']);
    ensure($res->getStatusCode()===302&&session('errors')->has('password'),'Profile validation');
    ensure(!str_contains(file_get_contents(config('session.files').'/'.session()->getId()),'failed-secret'),'Password in old input');
    $request('PUT','/auth/setting',['_token'=>$csrf,'password'=>'profile-reset','password_confirmation'=>'profile-reset']);
    ensure(Illuminate\Support\Facades\Hash::check('profile-reset',$actor->fresh()->password),'Profile confirmed reset');
    $role->permissions()->attach($permission);
    $actor->roles()->detach();
    // Route grant is method-scoped: read does not grant mutation or grant removal.
    $read=Encore\Admin\Auth\Database\Permission::create(['slug'=>'read','name'=>'Read','http_method'=>['GET'],'http_path'=>"/auth/users*\n/auth/roles*\n/auth/permissions*"]);
    $actor->permissions()->attach($read);
    foreach ([['PUT','/auth/users/'.$target->id,['name'=>'denied']],['DELETE','/auth/users/'.$target->id,[]],['POST','/auth/users',$data],['PUT','/auth/roles/'.$role->id,['permissions'=>['']]],['DELETE','/auth/permissions/'.$createdGrant->id,[]]] as [$method,$path,$input]) {
        ensure($request($method,$path,$input+['_token'=>$csrf],$ajax)->getStatusCode()===403,'Denied mutation '.$path);
    }
    ensure($target->fresh()->name==='Renamed'&&$role->permissions()->whereKey($permission->id)->exists()&&$createdGrant->fresh()!==null,'Denied state preservation');
    $manage=Encore\Admin\Auth\Database\Permission::create(['slug'=>'manage-role','name'=>'Manage role','http_method'=>['PUT'],'http_path'=>'/auth/roles/*']);
    $actor->permissions()->attach($manage);
    $res=$request('PUT','/auth/roles/'.$role->id,['_token'=>$csrf,'permissions'=>['']],$ajax);
    ensure(json_decode($res->getContent(),true)['status']===true&&!$role->permissions()->exists(),'Explicit nonadmin grant removal');
    $actor->permissions()->detach($manage);
    $actor->roles()->attach($adminRole);
    $target->roles()->attach($role); $target->permissions()->attach($createdGrant);
    foreach (['roles'=>$role,'permissions'=>$createdGrant] as $resource=>$model) {
        $res=$request('DELETE','/auth/'.$resource.'/'.$model->id,['_token'=>$csrf],$ajax);
        ensure(json_decode($res->getContent(),true)['status']===true&&$model->fresh()===null,'Allowed grant resource delete '.$resource);
    }
    ensure(!$target->roles()->exists()&&!$target->permissions()->exists(),'Grant resource deletion cleans pivots');
    ensure(json_decode($request('DELETE','/auth/users/'.$target->id,['_token'=>$csrf],$ajax)->getContent(),true)['status']===true,'Allowed user delete');
    ensure($target->fresh()===null,'Delete row');
    // Existing policy: UI suppresses id=1 delete; server permits authorized self/last-admin deletion.
    ensure(json_decode($request('DELETE','/auth/users/'.$actor->id,['_token'=>$csrf],$ajax)->getContent(),true)['status']===true,'Existing self/last-admin policy');
    ensure(FormIdentityFixture::count()===0,'Self/last-admin delete persisted');
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'engine'=>getenv('DB_CONNECTION'),'vendor'=>realpath(Composer\InstalledVersions::getInstallPath('laravel/framework')),'csrf_unit_bypass'=>false,'configured_guard_provider_model_connection_table'=>true,'user_list_create_edit_detail_delete'=>true,'invalid_missing_duplicate_array_and_confirmation'=>true,'create_reset_hash_and_blank_preservation'=>true,'user_role_direct_grant_assignment_removal'=>true,'role_permission_forms'=>true,'profile_name_confirmed_password_blank_preservation_redirect_validation'=>true,'method_scoped_denials_preserve_state'=>true,'password_absent_html_old_input'=>true,'avatar_rendered_storage_owned_by_issue14'=>true,'existing_policy_authorized_self_last_admin_deletion_permitted'=>true,'browser_acceptance_pending_issue19'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) { fwrite(STDERR,$e."\n"); exit(1); }
