<?php
/** Real HTTP kernel, native vendors, actual log grid, method grants and CSRF. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    $app = PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>false, 'session.driver'=>'file', 'session.cookie'=>'grid_session', 'auth.defaults.guard'=>'web', 'admin.auth.guard'=>'backoffice', 'auth.guards.backoffice'=>['driver'=>'session','provider'=>'admin'], 'auth.providers.admin'=>['driver'=>'eloquent','model'=>Encore\Admin\Auth\Database\Administrator::class], 'admin.route.prefix'=>'nested/control', 'admin.route.domain'=>'admin.example.test', 'admin.operation_log.enable'=>true, 'admin.operation_log.except'=>['nested/control/auth/logs*','nested/control/auth/login','nested/control/csrf']]);
    config(['database.connections.audit'=>config('database.connections.'.config('database.default')), 'admin.database.connection'=>'audit']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'audit_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $user=Encore\Admin\Auth\Database\Administrator::create(['username'=>'operator','name'=>'Audit operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class,Encore\Admin\Middleware\Pjax::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->get('csrf', fn()=>response()->json(['csrf'=>csrf_token()]));
        $router->get('work', fn()=> 'operation accepted');
        $router->get('auth/logs',[Encore\Admin\Controllers\LogController::class,'index']);
        $router->delete('auth/logs/{id}',[Encore\Admin\Controllers\LogController::class,'destroy']);
    });
    $router->getRoutes()->refreshNameLookups();
    $cookies=[];
    $request=function($method,$path,$data=[],$pjax=false)use($app,&$cookies){
        $app['auth']->forgetGuards();
        $req=Illuminate\Http\Request::create('https://admin.example.test'.$path,$method,$data,$cookies,[],['HTTP_HOST'=>'admin.example.test','HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest','HTTP_ACCEPT'=>'application/json','HTTP_SEC_FETCH_SITE'=>'cross-site','REMOTE_ADDR'=>'192.0.2.15'] + ($pjax?['HTTP_X_PJAX'=>'true','HTTP_X_PJAX_CONTAINER'=>'#pjax-container']:[]));
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);
        foreach($res->headers->getCookies()as$cookie)$cookies[$cookie->getName()]=$cookie->getValue();
        ensure(config('auth.defaults.guard')==='web','Host guard changed');
        return $res;
    };
    $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $exception){ fwrite(STDERR,'HTTP exception '.get_class($exception)."\n"); });
    $app->instance('env','grid-acceptance');ensure(!$app->runningUnitTests(),'CSRF bypass active');
    ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===302,'Guest read');
    $request('GET','/nested/control/auth/login');$csrf=session()->token();
    ensure($request('POST','/nested/control/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Login');
    $browse=Encore\Admin\Auth\Database\Permission::create(['name'=>'work','slug'=>'work','http_method'=>['GET'],'http_path'=>"/work\n/csrf"]);$user->permissions()->attach($browse);
    $csrf=json_decode($request('GET','/nested/control/csrf')->getContent(),true)['csrf'];
    $secret='synthetic-issue10-secret';
    ensure($request('GET','/nested/control/work',['password'=>$secret,'nested'=>['apiKey'=>$secret,'safe'=>'retained-value'],'_context'=>$secret])->getStatusCode()===200,'Middleware real request');
    $log=Encore\Admin\Auth\Database\OperationLog::firstOrFail();
    ensure($log->getConnectionName()==='audit' && $log->getTable()==='audit_operation_log' && $log->user_id===$user->id && $log->method==='GET' && $log->path==='nested/control/work' && $log->ip==='192.0.2.15' && $log->created_at!==null,'Audit metadata');
    $db=$app['db']->connection('audit');$table=config('admin.database.operation_log_table');
    $raw=$db->table($table)->where('id',$log->id)->value('input');
    ensure(!str_contains($raw,$secret) && str_contains($raw,'retained-value'),'Raw persistence redaction');
    $before=Encore\Admin\Auth\Database\OperationLog::count();
    config(['admin.operation_log.enable'=>false]);
    ensure($request('GET','/nested/control/work',['password'=>$secret])->getStatusCode()===200 && $db->table($table)->count()===$before,'Logging disabled');
    config(['admin.operation_log.enable'=>true,'admin.operation_log.except'=>['GET:nested/control/work','nested/control/auth/logs*','nested/control/auth/login','nested/control/csrf']]);
    ensure($request('GET','/nested/control/work',['password'=>$secret])->getStatusCode()===200 && $db->table($table)->count()===$before,'Logging method/path exclusion');
    config(['admin.operation_log.except'=>['nested/control/auth/logs*','nested/control/auth/login','nested/control/csrf'],'admin.operation_log.allowed_methods'=>['post']]);
    ensure($request('GET','/nested/control/work',['password'=>$secret])->getStatusCode()===200 && $db->table($table)->count()===$before,'Allowed methods exclusion');
    config(['admin.operation_log.allowed_methods'=>[],'admin.database.operation_log_table'=>'missing_audit_fixture','admin.operation_log.failure_policy'=>'ignore']);
    $res=$request('GET','/nested/control/work',['password'=>$secret]);ensure($res->getStatusCode()===200 && !str_contains($res->getContent(),$secret),'Ignored logging failure');
    config(['admin.operation_log.failure_policy'=>'abort']);
    $res=$request('GET','/nested/control/work',['password'=>$secret]);ensure($res->getStatusCode()===503 && !str_contains($res->getContent(),$secret) && !str_contains($res->getContent(),'missing_audit_fixture'),'Aborted logging failure');
    config(['admin.database.operation_log_table'=>$table,'admin.operation_log.failure_policy'=>'ignore']);
    ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===403,'Denied read');
    ensure($request('DELETE','/nested/control/auth/logs/'.$log->id,['_token'=>$csrf])->getStatusCode()===403 && Encore\Admin\Auth\Database\OperationLog::count()===$before,'Denied delete');
    $view=Encore\Admin\Auth\Database\Permission::create(['name'=>'view logs','slug'=>'view-logs','http_method'=>['GET'],'http_path'=>'/auth/logs*']);$user->permissions()->attach($view);
    $historic=json_encode(['password'=>$secret,'nested'=>['clientSecret'=>$secret,'safe'=>'historic-safe'],'html'=>'<script>unsafe()</script>']);
    $id=$db->table($table)->insertGetId(['user_id'=>$user->id,'method'=>'POST','path'=>'historic-record','ip'=>'192.0.2.16','input'=>$historic,'created_at'=>'2026-01-02 12:30:00','updated_at'=>'2026-01-02 12:30:00']);
    for($i=0;$i<3;$i++) Encore\Admin\Auth\Database\OperationLog::create(['user_id'=>$user->id,'method'=>'PATCH','path'=>'page-'.$i,'ip'=>'192.0.2.17','input'=>['safe'=>'page-value']]);
    $params=['user_id'=>$user->id,'method'=>'POST','path'=>'historic','ip'=>'192.0.2.16','created_at'=>['start'=>'2026-01-01','end'=>'2026-01-03'],'per_page'=>1,'_sort'=>['column'=>'id','type'=>'desc']];
    $res=$request('GET','/nested/control/auth/logs',$params);$html=$res->getContent();
    ensure($res->getStatusCode()===200 && str_contains($html,'historic-record') && str_contains($html,'historic-safe') && str_contains($html,'[REDACTED]') && !str_contains($html,$secret) && !str_contains($html,'<script>unsafe()'),'Real historic log grid redaction/filter');
    ensure($db->table($table)->where('id',$id)->value('input')===$historic,'Historic raw row preserved');
    $res=$request('GET','/nested/control/auth/logs',['per_page'=>2,'page'=>2],true);
    ensure($res->getStatusCode()===200 && !str_contains($res->getContent(),'<html') && str_contains($res->getContent(),'page-value'),'PJAX page rows');
    foreach([false,true]as$pjax){$res=$request('GET','/nested/control/auth/logs',$params+['page'=>99],$pjax);ensure($res->getStatusCode()===302,'Invalid page redirect');parse_str(parse_url($res->headers->get('Location'),PHP_URL_QUERY),$query);ensure($query['page']==1 && $query['path']==='historic' && $query['_sort']['column']==='id','Redirect state');}
    $res=$request('GET','/nested/control/auth/logs',['path'=>'missing-fixture']);ensure($res->getStatusCode()===200 && !str_contains($res->getContent(),'historic-record'),'Empty results');
    ensure($request('DELETE','/nested/control/auth/logs/'.$id,['_token'=>$csrf])->getStatusCode()===403 && $db->table($table)->where('id',$id)->exists(),'GET grant cannot delete');
    $delete=Encore\Admin\Auth\Database\Permission::create(['name'=>'delete logs','slug'=>'delete-logs','http_method'=>['DELETE'],'http_path'=>'/auth/logs/*']);$user->permissions()->attach($delete);
    foreach (['bad-key', $id.',bad-key', '0'] as $invalid) { ensure($request('DELETE','/nested/control/auth/logs/'.$invalid,['_token'=>$csrf])->getStatusCode()===422 && $db->table($table)->where('id',$id)->exists(), 'Malformed deletion cannot remove records'); }
    ensure($request('DELETE','/nested/control/auth/logs/'.$id)->getStatusCode()===419 && $db->table($table)->where('id',$id)->exists(),'Delete CSRF');
    ensure($request('DELETE','/nested/control/auth/logs/'.$id,['_token'=>$csrf])->getStatusCode()===200 && !$db->table($table)->where('id',$id)->exists(),'Single delete');
    $ids=$db->table($table)->pluck('id')->all();
    ensure($request('DELETE','/nested/control/auth/logs/'.implode(',',$ids),['_token'=>$csrf])->getStatusCode()===200 && $db->table($table)->count()===0,'Batch delete');
    $router->setCompiledRoutes($router->getRoutes()->compile());
    ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===200,'Compiled empty log grid');
    $user->permissions()->detach($view);ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===403,'Compiled revoked view');
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'engine'=>getenv('DB_CONNECTION'),'native_vendor'=>true,'csrf_unit_bypass'=>false,'actual_log_grid'=>true,'configured_connection_actor_path_method_ip_time'=>true,'real_middleware_stored_values_redacted'=>true,'historic_rows_redacted_without_rewrite'=>true,'logging_enable_method_path_exclusions_failure_policy'=>true,'all_log_filters'=>true,'normal_pjax_pagination_empty_redirect_state'=>true,'guest_denied_allowed_read_single_batch_delete'=>true,'get_grant_not_delete'=>true,'compiled_routes_revocation'=>true],JSON_PRETTY_PRINT)."\n";
} catch(Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n"); exit(1); }
