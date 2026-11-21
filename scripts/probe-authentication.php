<?php
/** Native HTTP kernel acceptance, independent Composer vendor and real database. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class IdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    $app = PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true, 'session.driver'=>'file', 'session.serialization'=>($argv[1] ?? 'php'), 'session.cookie'=>'identity_session', 'session.path'=>'/', 'session.domain'=>'.example.test', 'auth.defaults.guard'=>'web', 'admin.route.prefix'=>'control', 'admin.route.domain'=>'admin.example.test', 'admin.route.name_prefix'=>'identity', 'admin.auth.guard'=>'backoffice', 'admin.auth.username'=>'name', 'auth.guards.backoffice'=>['driver'=>'session','provider'=>'identity'], 'auth.providers.identity'=>['driver'=>'eloquent','model'=>IdentityFixture::class], 'auth.guards.web'=>['driver'=>'session','provider'=>'identity'], 'admin.auth.controller'=>Encore\Admin\Controllers\AuthController::class]);
    config(['database.connections.identity_connection'=>config('database.connections.'.config('database.default')), 'admin.database.connection'=>'identity_connection']);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish', ['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
    $user = IdentityFixture::create(['username'=>'admin','name'=>'Identity','password'=>Illuminate\Support\Facades\Hash::make('secret')]);
    $controller = new class extends Encore\Admin\Controllers\AuthController { public function profileFixture() { return $this->settingForm(); } };
    ensure($controller->profileFixture()->model() instanceof IdentityFixture, 'Profile provider model');
    $hash = $user->password;
    $router = $app['router'];
    $router->middleware(['web', Encore\Admin\Middleware\Authenticate::class])->domain('admin.example.test')->prefix('control')->group(function () use ($router) {
        $router->get('auth/login', [Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('identity.login');
        $router->post('auth/login', [Encore\Admin\Controllers\AuthController::class,'postLogin']);
        // Match the bundled registrations: Router::get includes HEAD automatically.
        $router->get('auth/logout', [Encore\Admin\Controllers\AuthController::class,'getLogout']);
        $router->post('auth/logout', [Encore\Admin\Controllers\AuthController::class,'getLogout']);
        $router->get('auth/setting', [Encore\Admin\Controllers\AuthController::class,'getSetting'])->name('identity.setting');
        $router->get('identity', fn () => response()->json(['id'=>auth()->id(),'model'=>get_class(auth()->user()), 'connection'=>auth()->user()->getConnectionName(), 'token'=>csrf_token(), 'session'=>session()->getId(), 'errors'=>session('errors')?->getBag('login')->first('name'), 'notice'=>session('toastr') instanceof Illuminate\Support\MessageBag ? session('toastr')->first('message') : data_get(session('toastr'), 'message.0'), 'steps'=>session('steps')]));
        $router->post('mutation', function () { session()->flash('errors', (new Illuminate\Support\ViewErrorBag)->put('login',new Illuminate\Support\MessageBag(['name'=>'invalid']))); admin_toastr('saved'); session()->put('steps',['step'=>2,'nested'=>['ids'=>[1,2]]]); return 'mutated'; });
    });
    $router->middleware('web')->get('host', fn () => response()->json(['guard'=>auth()->getDefaultDriver(),'id'=>auth()->id(), 'token'=>csrf_token(), 'session'=>session()->getId()]));
    $router->middleware('web')->post('host-login', function () use ($user) { auth('web')->login($user); return 'host'; });
    $cookies=[];
    $request = function ($method,$path,$data=[], $headers=[]) use ($app,&$cookies) {
        $app['auth']->forgetGuards();
        $server=['HTTP_HOST'=>'admin.example.test', 'HTTPS'=>'on', 'SERVER_PORT'=>443];
        foreach ($headers as $key=>$value) $server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;
        $req=Illuminate\Http\Request::create('https://admin.example.test'.$path,$method,$data,$cookies,[],$server);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
        $response=$kernel->handle($req); $kernel->terminate($req,$response);
        foreach ($response->headers->getCookies() as $cookie) { if ($cookie->getExpiresTime() && $cookie->getExpiresTime()<time()) unset($cookies[$cookie->getName()]); else $cookies[$cookie->getName()]=$cookie->getValue(); }
        ensure(config('auth.defaults.guard')==='web', 'Default guard leaked');
        return $response;
    };
    $app->instance('env', 'authentication-acceptance');
    ensure(!$app->runningUnitTests(), 'CSRF unit bypass active');
    $host=json_decode($request('GET','/host')->getContent(),true); $token=$host['token']; $before=$host['session'];
    ensure($request('POST','/host-login',['_token'=>$token])->getStatusCode()===200,'Host login');
    ensure($request('GET','/control/identity')->getStatusCode()===302,'Guest identity redirect');
    $login=$request('GET','/control/auth/login');
    ensure(str_contains($login->getContent(),'name="name"'),'Configured username view');
    $token=session()->token();
    foreach ([[],['_token'=>'invalid']] as $data) ensure($request('POST','/control/auth/login',$data+['name'=>'Identity','password'=>'secret'],['Sec-Fetch-Site'=>'cross-site'])->getStatusCode()===419,'Unprotected login');
    $invalid=$request('POST','/control/auth/login',['_token'=>$token,'name'=>'Identity','password'=>'wrong']);
    ensure($invalid->getStatusCode()===302,'Invalid login response '.$invalid->getStatusCode().' '.substr($invalid->getContent(),0,250));
    ensure(!str_contains(file_get_contents(config('session.files').'/'.session()->getId()),'wrong'),'Failed password flashed');
    ensure($request('GET','/control/identity')->getStatusCode()===302,'Invalid credentials authenticated');
    $before=session()->getId();
    $success=$request('POST','/control/auth/login',['_token'=>$token,'name'=>'Identity','password'=>'secret','remember'=>'1']);
    ensure($success->getStatusCode()===302 && str_ends_with($success->headers->get('Location'),'/control/identity'),'Intended redirect');
    $remember=array_filter($success->headers->getCookies(),fn ($cookie)=>str_starts_with($cookie->getName(),'remember_backoffice_'));
    ensure(count($remember)===1,'Remember cookie');
    foreach ($success->headers->getCookies() as $cookie) ensure($cookie->getPath()==='/' && $cookie->getDomain()==='.example.test','Consumer cookie scope changed');
    $identity=json_decode($request('GET','/control/identity')->getContent(),true);
    ensure($identity['id']===$user->id && $identity['model']===IdentityFixture::class && $identity['connection']==='identity_connection','Configured identity');
    ensure($identity['session']!==$before && $identity['token']!==$token,'Login rotation'); $token=$identity['token'];
    $profile=$request('GET','/control/auth/setting');
    ensure($profile->getStatusCode()===200 && !str_contains($profile->getContent(), $hash) && str_contains($profile->getContent(),'admin.example.test/control/auth/setting'), 'Configured profile route or hash exposure');
    $host=json_decode($request('GET','/host')->getContent(),true); ensure($host['guard']==='web' && $host['id']===$user->id,'Host guard coexistence');
    foreach (['/control/mutation','/control/auth/logout'] as $path) foreach ([[],['_token'=>'invalid']] as $data) ensure($request('POST',$path,$data,['Sec-Fetch-Site'=>'cross-site'])->getStatusCode()===419,'Unprotected mutation '.$path);
    ensure($request('POST','/control/mutation',['_token'=>$token],['Sec-Fetch-Site'=>'cross-site'])->getStatusCode()===200,'Valid token fallback');
    $roundtrip=json_decode($request('GET','/control/identity')->getContent(),true);
    ensure($roundtrip['errors']==='invalid' && $roundtrip['notice']==='saved' && $roundtrip['steps']['nested']['ids']===[1,2],'PHP session payload roundtrip');
    ensure($request('POST','/control/mutation',[],['Sec-Fetch-Site'=>'same-origin'])->getStatusCode()===200,'Same origin verification');
    foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $beforeRead=json_decode($request('GET','/control/identity')->getContent(),true);
        $read=$request($method,'/control/auth/logout',[],['Sec-Fetch-Site'=>'cross-site']);
        ensure(in_array($read->getStatusCode(), in_array($method, ['GET','HEAD'], true) ? [302] : [200,405], true), 'Unexpected logout method status '.$method);
        $afterRead=json_decode($request('GET','/control/identity')->getContent(),true);
        ensure(($afterRead['id'] ?? null)===$user->id && $afterRead['session']===$beforeRead['session'] && $afterRead['token']===$beforeRead['token'], 'Read/unsupported logout mutated identity/session '.$method);
    }
    unset($cookies['identity_session']);
    $remembered=json_decode($request('GET','/control/identity')->getContent(),true);
    ensure(($remembered['id'] ?? null)===$user->id, 'Remember cookie did not restore login');
    $token=$remembered['token'];
    foreach ([[], ['_token'=>'invalid']] as $data) ensure($request('POST','/control/auth/logout',$data,['Sec-Fetch-Site'=>'cross-site'])->getStatusCode()===419, 'POST logout protection after read/unsupported requests');
    $logout=$request('POST','/control/auth/logout',['_token'=>$token]); ensure($logout->getStatusCode()===302,'Logout failed');
    ensure($request('GET','/control/identity')->getStatusCode()===302,'Logout identity persisted');
    $after=json_decode($request('GET','/host')->getContent(),true); ensure($after['session']!==$identity['session'] && $after['token']!==$token && $after['id']===null,'Logout invalidation');
    config(['admin.session'=>['path'=>'/control', 'domain'=>'admin.example.test']]);
    $router->middleware(['web', 'admin.session'])->get('scoped-cookie', fn () => 'scoped');
    $scoped=$request('GET','/scoped-cookie');
    ensure($scoped->getStatusCode()===200, 'Scoped cookie request');
    foreach ($scoped->headers->getCookies() as $cookie) if (in_array($cookie->getName(), ['identity_session','XSRF-TOKEN'], true)) ensure($cookie->getPath()==='/control' && $cookie->getDomain()==='admin.example.test', 'Explicit cookie scope timing '.$cookie->getName().' '.$cookie->getPath().' '.$cookie->getDomain());
    ensure(config('session.path')==='/' && config('session.domain')==='.example.test', 'Explicit cookie scope leaked');
    // Explicit disposable JSON opt-in; production serialization is never changed.

    $handler = new Illuminate\Session\ArraySessionHandler(120);
    $json = new Illuminate\Session\Store('json_fixture', $handler, null, 'json');
    $json->start();
    $json->flash('errors', (new Illuminate\Support\ViewErrorBag)->put('login', new Illuminate\Support\MessageBag(['name'=>'invalid'])));
    $json->flash('toastr', new Illuminate\Support\MessageBag(['message'=>'saved','type'=>'success']));
    $json->put('steps', ['step'=>2,'nested'=>['ids'=>[1,2]]]);
    $id=$json->getId(); $json->save();
    $jsonReload = new Illuminate\Session\Store('json_fixture', $handler, $id, 'json'); $jsonReload->start();
    ensure($jsonReload->get('errors')->getBag('login')->first('name')==='invalid' && $jsonReload->get('steps')['nested']['ids']===[1,2], 'JSON validation/steps fixture');
    ensure(is_array($jsonReload->get('toastr')), 'JSON notification representation changed unexpectedly');
    $originalSession = app('session');
    Illuminate\Support\Facades\Session::swap($jsonReload);
    ensure(str_contains(view('admin::partials.toastr')->render(), "toastr.success('saved'"), 'JSON toastr render');
    Illuminate\Support\Facades\Session::swap($originalSession);
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'engine'=>getenv('DB_CONNECTION'),'vendor'=>realpath(Composer\InstalledVersions::getInstallPath('laravel/framework')),'csrf_unit_bypass'=>false,'login_invalid_valid_intended_remember'=>true,'remember_cookie_reauthenticates'=>true,'profile_uses_provider_model'=>true,'configured_username_guard_provider_model_connection'=>true,'host_guard_isolation'=>true,'selected_session_serialization'=>config('session.serialization'),'selected_session_payload_roundtrip'=>true,'consumer_cookie_scope_preserved'=>true,'explicit_cookie_scope_and_restoration'=>true,'login_rotation_logout_invalidation'=>true,'get_logout_safe'=>true,'cross_site_head_logout_preserves_identity_session_token'=>true,'unsupported_logout_methods_do_not_mutate'=>true,'cross_site_missing_invalid_tokens_rejected'=>true,'valid_token_cross_site_fallback'=>true,'same_origin_without_token_accepted'=>true,'https_requests'=>true,'json_opt_in_errors_steps_roundtrip'=>true,'json_toastr_array_renders_successfully'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) { fwrite(STDERR,$e."\n"); exit(1); }
