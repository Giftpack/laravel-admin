<?php
/** Real HTTP kernel + CSRF + compiled routes + custom database names/connection. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    require_once '/application/tests/Fixtures/AuthorizationHandlers.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    $app = PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>false,'session.driver'=>'file','session.cookie'=>'authorization_session','auth.defaults.guard'=>'web','admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'admin'],'auth.providers.admin'=>['driver'=>'eloquent','model'=>Encore\Admin\Auth\Database\Administrator::class],'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test','admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.grants'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'grants']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'security_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $user=Encore\Admin\Auth\Database\Administrator::create(['username'=>'operator','name'=>'operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $record=Encore\Admin\Auth\Database\Administrator::create(['username'=>'target','name'=>'target','password'=>'unused']);
    $grant=Encore\Admin\Auth\Database\Permission::create(['name'=>'browse','slug'=>'browse','http_method'=>['GET'],'http_path'=>'/contexts']);$user->permissions()->attach($grant);
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router,$record){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        foreach(['action','form'] as $kind)$router->post('_handle_'.$kind.'_',[Encore\Admin\Controllers\HandleController::class,'handle'.ucfirst($kind)])->name(admin_get_route('handle-'.$kind));
        foreach(['selectable','renderable'] as $kind)$router->get('_handle_'.$kind.'_',[Encore\Admin\Controllers\HandleController::class,'handle'.ucfirst($kind)])->name(admin_get_route('handle-'.$kind));
        $router->get('contexts',function()use($record){
            $row=(new BoundaryRow)->setRow($record)->setGrid(new Encore\Admin\Grid(new Encore\Admin\Auth\Database\Administrator));
            $picker=new class('BoundarySelectable') extends Encore\Admin\Form\Field\ValuePicker { public function url(){return $this->getLoadUrl();} };
            $readGrid=new Encore\Admin\Grid(new Encore\Admin\Auth\Database\Administrator);
            $readColumn=new Encore\Admin\Grid\Column('name','Name');
            $readRow=(object)['id'=>'allowed'];
            $renderUrl=(new BoundaryModal('', $readGrid, $readColumn, $readRow))->producerUrl('BoundaryRenderable');
            $expandUrl=(new BoundaryExpand('', $readGrid, $readColumn, $readRow))->producerUrl('BoundaryRenderable');
            foreach(['column-modal'=>$renderUrl,'column-expand'=>$expandUrl] as $component=>$url) {
                $html=view('admin::components.'.$component,['url'=>$url,'name'=>'scope','key'=>'allowed','value'=>'view','title'=>'Read','html'=>'','grid'=>false,'async'=>true,'elementClass'=>'expand','expand'=>false,'loadGrid'=>false])->render();
                ensure(preg_match('/load\(("[^"]*")\+/', $html, $match)===1 && json_decode($match[1],true)===$url, 'Rendered async URL loses signed context');
            }
            $formHtml=(new BoundaryForm)->render();
            $formContext=(new Symfony\Component\DomCrawler\Crawler($formHtml))->filter('input[name="_context"]')->attr('value');
            return response()->json(['csrf'=>csrf_token(),'render_url'=>$renderUrl,'row_url'=>$row->getHandleRoute(),'picker_url'=>$picker->url(),'form_context'=>$formContext,'render_context'=>Encore\Admin\Security\HandlerContext::issue('renderable','BoundaryRenderable',['key'=>'allowed']),'default_context'=>Encore\Admin\Security\HandlerContext::issue('action','BoundaryDefaultAction'),'batch_context'=>Encore\Admin\Security\HandlerContext::issue('action','BoundaryBatch',['model'=>'BoundaryRecord'])]);
        });
        $router->get('protected',fn()=> 'protected');
        $router->get('auth/logs',fn()=> Encore\Admin\Auth\Database\OperationLog::count());
        $router->delete('auth/logs/{id}',[Encore\Admin\Controllers\LogController::class,'destroy']);
    });
    $router->getRoutes()->refreshNameLookups();
    $cookies=[];
    $request=function($method,$path,$data=[],$cross=false)use($app,&$cookies){
        $app['auth']->forgetGuards();
        $req=Illuminate\Http\Request::create('https://admin.example.test'.$path,$method,$data,$cookies,[],['HTTP_HOST'=>'admin.example.test','HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest','HTTP_ACCEPT'=>'application/json','HTTP_SEC_FETCH_SITE'=>$cross?'cross-site':'same-origin']);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);
        foreach($res->headers->getCookies()as$cookie)$cookies[$cookie->getName()]=$cookie->getValue();
        ensure(config('auth.defaults.guard')==='web','Host guard changed');
        return $res;
    };
    $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $exception){ fwrite(STDERR, 'HTTP exception '.get_class($exception).' at '.$exception->getFile().':'.$exception->getLine()."\n"); });
    $app->instance('env','authorization-acceptance');ensure(!$app->runningUnitTests(),'CSRF bypass active');
    foreach(['action'=>'POST','form'=>'POST','selectable'=>'GET','renderable'=>'GET']as$kind=>$method)ensure($request($method,'/nested/control/_handle_'.$kind.'_')->getStatusCode()===302,'Guest generic boundary');
    $request('GET','/nested/control/auth/login');$csrf=session()->token();
    ensure($request('POST','/nested/control/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>$csrf],true)->getStatusCode()===302,'Native login');
    $contextResponse=$request('GET','/nested/control/contexts');$contexts=json_decode($contextResponse->getContent(),true);ensure(isset($contexts['row_url']),'Context producer status '.$contextResponse->getStatusCode());$csrf=$contexts['csrf'];
    $rowPath=parse_url($contexts['row_url'],PHP_URL_PATH);parse_str(parse_url($contexts['row_url'],PHP_URL_QUERY),$rowQuery);
    $data=$rowQuery+['_action'=>'BoundaryRow','_model'=>'Encore_Admin_Auth_Database_Administrator','_key'=>(string)$record->id];
    foreach([[],['_token'=>'invalid']]as$token)ensure($request('POST',$rowPath,$data+$token,true)->getStatusCode()===419,'Action CSRF');
    ensure($request('POST',$rowPath,$data+['_token'=>$csrf],true)->getStatusCode()===403,'Browse became action permission');
    $before=BoundaryRow::$constructed;
    ensure($request('POST',$rowPath,array_replace($data,['_action'=>'BoundaryUnrelated','_token'=>$csrf]),true)->getStatusCode()===403,'Unrelated class');
    ensure(BoundaryRow::$constructed===$before && $record->fresh()->name==='target','Denied handler mutated');
    foreach([['_model'=>'BoundaryRecord'],['_key'=>(string)$user->id],['_context'=>'forged']] as $tamper) {
        ensure($request('POST',$rowPath,array_replace($data,$tamper,['_token'=>$csrf]),true)->getStatusCode()===403,'Action model/key/context tampering');
        ensure(BoundaryRow::$constructed===$before && $record->fresh()->name==='target','Forged scope constructed handler or mutated record');
    }
    $change=Encore\Admin\Auth\Database\Permission::create(['name'=>'change','slug'=>'change','http_method'=>['POST'],'http_path'=>'/records/*']);$user->permissions()->attach($change);
    ensure($request('POST',$rowPath,$data+['_token'=>$csrf],true)->getStatusCode()===200 && $record->fresh()->name==='changed','Allowed row action');
    ensure($request('POST','/nested/control/_handle_action_',['_action'=>'BoundaryDefaultAction','_context'=>$contexts['default_context'],'_token'=>$csrf],true)->getStatusCode()===403,'Default action fail closed');
    $form=['_form_'=>'BoundaryForm','_context'=>$contexts['form_context'],'_token'=>$csrf,'password'=>'fixture-audit-credential','nested'=>['apiToken'=>'fixture-audit-credential'],'note'=>'retain-context'];
    ensure($request('POST','/nested/control/_handle_form_',$form,true)->getStatusCode()===200,'Allowed form');
    $pickerPath=parse_url($contexts['picker_url'],PHP_URL_PATH);parse_str(parse_url($contexts['picker_url'],PHP_URL_QUERY),$pickerData);
    ensure($request('GET',$pickerPath,$pickerData+['page'=>2,'search'=>'term'])->getStatusCode()===200,'Actual picker URL numeric boolean args');
    $pickerData['args'][0]='tamper';ensure($request('GET',$pickerPath,$pickerData)->getStatusCode()===403,'Picker args tampering');
    parse_str(parse_url($contexts['render_url'],PHP_URL_QUERY),$render);$render['key']='allowed';
    ensure($request('GET','/nested/control/_handle_renderable_',$render)->getStatusCode()===200,'Allowed render');
    $render['key']='foreign';ensure($request('GET','/nested/control/_handle_renderable_',$render)->getStatusCode()===403,'Render key tampering');
    $user->permissions()->detach($change);ensure($request('POST',$rowPath,$data+['_token'=>$csrf],true)->getStatusCode()===403,'Live action revocation');
    ensure($request('POST','/nested/control/_handle_form_',$form,true)->getStatusCode()===403,'Live form revocation');
    $user->permissions()->detach($grant);ensure($request('GET',$pickerPath,parse_url($contexts['picker_url'],PHP_URL_QUERY)?(function($url){parse_str(parse_url($url,PHP_URL_QUERY),$x);return$x;})($contexts['picker_url']):[])->getStatusCode()===403,'Live read revocation');
    $raw=$app['db']->connection('grants')->table(config('admin.database.operation_log_table'))->pluck('input')->implode('');
    ensure(!str_contains($raw,'fixture-audit-credential') && !str_contains($raw,'fixture-password') && str_contains($raw,'retain-context'),'Audit redaction before storage');
    $log=Encore\Admin\Auth\Database\OperationLog::first();ensure($log->user_id===$user->id && $log->getConnectionName()==='grants' && $log->getTable()==='security_operation_log','Audit attribution/connection/table');
    // Permission middleware remains active with cached-route representation and custom prefix/domain.
    $router->setCompiledRoutes($router->getRoutes()->compile());
    ensure($request('GET','/nested/control/protected')->getStatusCode()===403,'Compiled route denies missing grant');
    $user->permissions()->attach($grant);ensure($request('GET','/nested/control/contexts')->getStatusCode()===200,'Compiled route preserves GET grant');
    config(['admin.auth.excepts'=>['auth/login','protected']]);
    ensure($request('GET','/nested/control/protected')->getStatusCode()===200,'Configured permission exception');
    config(['admin.auth.excepts'=>['auth/login']]);
    $count=Encore\Admin\Auth\Database\OperationLog::count();
    ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===403,'Log view authorization');
    ensure($request('DELETE','/nested/control/auth/logs/'.$log->id,['_token'=>$csrf],true)->getStatusCode()===403 && $log->fresh()!==null,'Log delete authorization');
    $logs=Encore\Admin\Auth\Database\Permission::create(['name'=>'logs','slug'=>'logs','http_method'=>['GET','DELETE'],'http_path'=>'/auth/logs*']);$user->permissions()->attach($logs);
    ensure($request('GET','/nested/control/auth/logs')->getStatusCode()===200,'Log view grant');
    ensure($request('DELETE','/nested/control/auth/logs/'.$log->id,['_token'=>$csrf],true)->getStatusCode()===200 && Encore\Admin\Auth\Database\OperationLog::find($log->id)===null,'Log delete grant');
    $role=Encore\Admin\Auth\Database\Role::create(['name'=>'editors','slug'=>'editors']);$role->permissions()->attach($change);$user->roles()->attach($role);
    ensure($user->can('change'),'Custom pivot grant');
    try{$role->permissions()->sync([999999=>['invalid_pivot_column'=>'failure']]);throw new RuntimeException('Missing sync failure');}
    catch(Illuminate\Database\QueryException $expected){ensure($role->permissions()->whereKey($change->id)->exists(),'Custom connection sync rollback');}
    Encore\Admin\Auth\Database\Role::deleted(function($model){if($model->slug==='editors')throw new RuntimeException('fixture rollback');});
    try{$role->delete();throw new RuntimeException('Missing delete failure');}catch(RuntimeException $expected){ensure($expected->getMessage()==='fixture rollback','Unexpected deletion failure');}
    ensure($role->fresh() && $role->permissions()->exists() && $role->administrators()->exists(),'Custom connection delete rollback');
    Encore\Admin\Auth\Database\Role::flushEventListeners();$boot=new ReflectionMethod(Encore\Admin\Auth\Database\Role::class,'boot');$boot->invoke(null);
    $role->delete();ensure(!$user->can('change'),'Custom pivot cleanup');$logs->delete();ensure(!$user->can('logs'),'Custom direct pivot cleanup');
    $administrator=Encore\Admin\Auth\Database\Role::create(['name'=>'Administrator','slug'=>'administrator']);$user->roles()->attach($administrator);
    $adminContexts=json_decode($request('GET','/nested/control/contexts')->getContent(),true);
    ensure($request('POST','/nested/control/_handle_action_',['_action'=>'BoundaryDefaultAction','_context'=>$adminContexts['default_context'],'_token'=>$csrf],true)->getStatusCode()===200,'Administrator default handler bypass');
    $record->update(['name'=>'protected']);
    config(['admin.handler_abilities'=>['BoundaryRow'=>'change','BoundaryBatch'=>'change']]);
    ensure($request('POST',$rowPath,$data+['_token'=>$csrf],true)->getStatusCode()===403,'Administrator bypassed explicit action denial');
    ensure($request('POST','/nested/control/_handle_action_',['_action'=>'BoundaryBatch','_model'=>'BoundaryRecord','_key'=>[$record->id],'_context'=>$adminContexts['batch_context'],'_token'=>$csrf],true)->getStatusCode()===403,'Administrator ability bypassed model denial');
    $app['db']->connection('grants')->getSchemaBuilder()->create('boundary_key_records',function($table){$table->string('code')->primary();$table->string('name');});
    $zero=BoundaryKeyRecord::create(['code'=>'0','name'=>'target']);
    // Generate tokens inside the authenticated server context, as the existing producers do.
    $app['auth']->guard('backoffice')->setUser($user);
    $zeroRow=['_action'=>'BoundaryKeyRow','_model'=>'BoundaryKeyRecord','_key'=>'0','_context'=>Encore\Admin\Security\HandlerContext::issue('action','BoundaryKeyRow',['model'=>'BoundaryKeyRecord','key'=>'0']),'_token'=>$csrf];
    $zeroBatch=['_action'=>'BoundaryKeyBatch','_model'=>'BoundaryKeyRecord','_key'=>'0','_context'=>Encore\Admin\Security\HandlerContext::issue('action','BoundaryKeyBatch',['model'=>'BoundaryKeyRecord']),'_token'=>$csrf];
    $zeroPolicy=['_action'=>'BoundaryKeyPolicyRow','_model'=>'BoundaryKeyRecord','_key'=>'0','_context'=>Encore\Admin\Security\HandlerContext::issue('action','BoundaryKeyPolicyRow',['model'=>'BoundaryKeyRecord','key'=>'0']),'_token'=>$csrf];
    ensure($request('POST',$rowPath,$zeroRow,true)->getStatusCode()===200 && $zero->fresh()->name==='changed','Existing zero row key');
    foreach(['0',0,['0',0]] as $keys){$zero->update(['name'=>'target']);$zeroBatch['_key']=$keys;ensure($request('POST',$rowPath,$zeroBatch,true)->getStatusCode()===200 && $zero->fresh()->name==='changed','Existing scalar zero batch key');}
    $zero->update(['name'=>'protected']);$zeroBatch['_key']='0';$handled=BoundaryKeyBatch::$handled;
    ensure($request('POST',$rowPath,$zeroPolicy,true)->getStatusCode()===403,'Zero-key model policy row denial');
    ensure($request('POST',$rowPath,$zeroBatch,true)->getStatusCode()===403 && BoundaryKeyBatch::$handled===$handled && $zero->fresh()->name==='protected','Zero-key model policy batch denial');
    $zero->delete();
    foreach([['BoundaryKeyRow',$zeroRow],['BoundaryKeyBatch',$zeroBatch]] as [$class,$zeroData]){
        $authorized=$class::$authorized;$handled=$class::$handled;
        ensure($request('POST',$rowPath,$zeroData,true)->getStatusCode()===404 && $class::$authorized===$authorized && $class::$handled===$handled,'Missing zero subject reached policy/handler');
        foreach([null,'',' ',[],[null],[''],[[]],false,true,1.5] as $invalid){$zeroData['_key']=$invalid;ensure($request('POST',$rowPath,$zeroData,true)->getStatusCode()===403 && $class::$authorized===$authorized && $class::$handled===$handled,'Malformed key reached policy/handler');}
    }
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'engine'=>getenv('DB_CONNECTION'),'csrf_unit_bypass'=>false,'custom_prefix_domain_connection_tables'=>true,'generic_guest_denied_allowed'=>true,'signed_real_action_and_picker_producers'=>true,'class_model_key_args_tamper_rejected'=>true,'fresh_grant_revocation'=>true,'browse_not_mutation_permission'=>true,'compiled_routes'=>true,'log_view_delete_grants'=>true,'log_raw_storage_redacted'=>true,'host_guard_preserved'=>true,'custom_pivot_cleanup'=>true,'custom_connection_failed_sync_delete_rollback'=>true,'administrator_bypass'=>true,'explicit_action_model_denial_overrides_admin_and_ability'=>true,'configured_permission_exceptions'=>true,'widget_form_producer'=>true,'modal_expand_rendered_json_url_transport'=>true,'zero_string_key_actual_model_and_collection'=>true,'missing_subjects_stop_before_callbacks'=>true,'zero_model_policy_denial'=>true],JSON_PRETTY_PRINT)."\n";
} catch(Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1); }
