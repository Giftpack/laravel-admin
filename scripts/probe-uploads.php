<?php
/** Native form/controller acceptance; disposable engines, real middleware and cookies. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    class UploadIdentityFixture extends Encore\Admin\Auth\Database\Administrator {}
    class UploadSoftIdentityFixture extends UploadIdentityFixture { use Illuminate\Database\Eloquent\SoftDeletes; }
    class UploadHostFixture extends Illuminate\Database\Eloquent\Model { protected $table='upload_host_records'; protected $connection='forms'; protected $guarded=[]; public $timestamps=false; }
    class UploadProfileController extends Encore\Admin\Controllers\AuthController {
        protected function settingForm() { $form=parent::settingForm(); $form->fields()->first(fn ($field) => $field->column()==='avatar')->thumbnail('small',8,8); return $form; }
    }
    class UploadUserController extends Encore\Admin\Controllers\UserController {
        public function form() { $form=parent::form(); $form->fields()->first(fn ($field) => $field->column()==='avatar')->thumbnail('small',8,8); return $form; }
    }
    class AvatarFaultDisk {
        public $fail=null;
        public function __construct(public $disk) {}
        public function __call($method,$args) { if ($method===$this->fail) return false; return $this->disk->$method(...$args); }
    }
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['app.debug'=>true,'session.driver'=>'file','session.cookie'=>'forms_session','auth.defaults.guard'=>'web',
        'admin.auth.guard'=>'backoffice','auth.guards.backoffice'=>['driver'=>'session','provider'=>'forms'],
        'auth.providers.forms'=>['driver'=>'eloquent','model'=>UploadIdentityFixture::class],
        'admin.database.users_model'=>UploadIdentityFixture::class,'admin.route.prefix'=>'nested/control','admin.route.domain'=>'admin.example.test',
        'admin.operation_log.enable'=>true,'admin.operation_log.except'=>[]]);
    config(['database.connections.forms'=>config('database.connections.'.config('database.default')),'admin.database.connection'=>'forms']);
    foreach (['users','roles','permissions','menu','operation_log','role_users','role_permissions','user_permissions','role_menu'] as $name) config(['admin.database.'.$name.'_table'=>'forms_'.$name]);
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $adminRole=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=UploadIdentityFixture::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $actor->roles()->attach($adminRole);
    $router=$app['router'];
    $router->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class])->domain('admin.example.test')->prefix('nested/control')->group(function()use($router){
        $router->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
        $router->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
        $router->get('auth/setting',[UploadProfileController::class,'getSetting']);
        $router->put('auth/setting',[UploadProfileController::class,'putSetting']);
        $router->resource('auth/users',UploadUserController::class);
        $router->resource('auth/default-users',Encore\Admin\Controllers\UserController::class);
        $router->post('_handle_action_',[Encore\Admin\Controllers\HandleController::class,'handleAction'])->name(admin_get_route('handle-action'));
        foreach (['user'=>UploadIdentityFixture::class,'host'=>UploadHostFixture::class,'soft'=>UploadSoftIdentityFixture::class] as $kind=>$class) {
            $router->get('_upload-delete-context/'.$kind.'/{id}',function ($id) use ($class) {
                $row=$class::findOrFail($id);
                $action=(new Encore\Admin\Grid\Actions\Delete())->setGrid(new Encore\Admin\Grid(new $class()))->setRow($row);
                return response()->json(['url'=>$action->getHandleRoute(),'data'=>$action->parameters()+['_action'=>str_replace('\\','_',get_class($action)),'_key'=>$row->getKey()]]);
            });
        }
        $router->resource('auth/roles',Encore\Admin\Controllers\RoleController::class);
        $router->resource('auth/permissions',Encore\Admin\Controllers\PermissionController::class);
    });
    $router->getRoutes()->refreshNameLookups();
    $baseUrl=($argv[1] ?? 'relative')==='absolute'?'https://avatars.example.test/root':'/uploads/';
    config(['filesystems.disks.avatars'=>['driver'=>'local','root'=>storage_path('avatar-files'),'url'=>$baseUrl], 'admin.upload.disk'=>'avatars']);
    $disk=Illuminate\Support\Facades\Storage::disk('avatars');
    $fault=new AvatarFaultDisk($disk);
    Illuminate\Support\Facades\Storage::set('avatars',$fault);
    $serveAvatar=function ($path) use ($disk) {
        if (!$disk->exists($path)) return response('Missing avatar',404);
        return response($disk->get($path),200)->header('Content-Type','image/png');
    };
    $router->domain('admin.example.test')->get('/uploads/{path}',$serveAvatar)->where('path','.*');
    $router->domain('avatars.example.test')->get('/root/{path}',$serveAvatar)->where('path','.*');
    Illuminate\Http\Request::enableHttpMethodParameterOverride();
    $cookies=[];
    $request=function($method,$path,$data=[],$headers=[],$files=[])use($app,&$cookies){
        $app['auth']->forgetGuards();
        $uri=preg_match('~^https?://~',$path)?$path:'https://admin.example.test'.(str_starts_with($path,'/uploads/')?$path:'/nested/control'.$path);
        $server=['HTTP_HOST'=>parse_url($uri,PHP_URL_HOST),'HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_SEC_FETCH_SITE'=>'cross-site'];
        foreach ($headers as $key=>$value) $server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;
        $req=Illuminate\Http\Request::create($uri,$method,$data,$cookies,$files,$server);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$res=$kernel->handle($req);$kernel->terminate($req,$res);
        foreach ($res->headers->getCookies() as $cookie) $cookies[$cookie->getName()]=$cookie->getValue();
        ensure(config('auth.defaults.guard')==='web','Default guard leaked');
        return $res;
    };
    $app->instance('env','forms-acceptance'); ensure(!$app->runningUnitTests(),'CSRF bypass active');
    $request('GET','/auth/login'); $csrf=session()->token();
    ensure($request('POST','/auth/login',['username'=>'operator','password'=>'fixture-password','_token'=>$csrf])->getStatusCode()===302,'Login');
    $csrf=session()->token();
    $app['config']->set('app.debug', false);
    $ajax=['X-Requested-With'=>'XMLHttpRequest','Accept'=>'application/json'];
    $makeImage=function($name='avatar.png') {
        $path=tempnam(storage_path(),'avatar');$im=imagecreatetruecolor(4,2);imagefilledrectangle($im,0,0,3,1,0x00ff00);imagepng($im,$path);imagedestroy($im);
        return new Illuminate\Http\UploadedFile($path,$name,'image/png',null,true);
    };
    $createData=['_token'=>$csrf,'username'=>'target','name'=>'Target','password'=>'fixture-password','password_confirmation'=>'fixture-password'];
    $created=$request('POST','/auth/users',$createData,$ajax,['avatar'=>$makeImage('created-avatar.png')]);
    ensure($created->getStatusCode()===200&&json_decode($created->getContent(),true)['status']===true,'User create with avatar');
    $target=UploadIdentityFixture::where('username','target')->firstOrFail();
    $createdPath=$target->getRawOriginal('avatar');
    ensure($disk->exists($createdPath)&&$disk->exists(str_replace('.png','-small.png',$createdPath)),'Created user avatar and thumbnail');

    $journeys=[];
    foreach ([['/auth/setting',$actor],['/auth/users/'.$target->id,$target]] as [$path,$person]) {
        $data=['_token'=>$csrf,'name'=>'Avatar user','username'=>$person->username,'password'=>'','password_confirmation'=>''];
        $res=$request('PUT',$path,$data,$ajax,['avatar'=>$makeImage()]);
        ensure($res->getStatusCode()===($path==='/auth/setting'?302:200),'Upload '.$path.' '.$res->getStatusCode().' '.substr($res->getContent(),0,200));
        $old=$person->fresh()->getRawOriginal('avatar');
        if ($person->id===$target->id) ensure(!$disk->exists($createdPath)&&!$disk->exists(str_replace('.png','-small.png',$createdPath)),'Created avatar replaced cleanly');
        ensure($old && $disk->exists($old) && $disk->exists(str_replace('.png','-small.png',$old)),'Stored original/thumbnails '.$path);
        ensure($person->fresh()->avatar===rtrim($baseUrl,'/').'/'.$old,'Accessor URL '.$path);
        ensure($person->getConnectionName()==='forms'&&$person->getTable()==='forms_users','Configured model/connection');
        $render=$request('GET',$path==='/auth/setting'?$path:$path.'/edit');
        ensure($render->getStatusCode()===200,'Avatar edit render '.$path);
        $dom=new DOMDocument();@$dom->loadHTML($render->getContent());
        $input=(new DOMXPath($dom))->query('//input[@name="avatar"]')->item(0);
        ensure($input!==null,'Avatar input missing '.$path);
        $preview=$input->getAttribute('data-initial-preview');
        ensure($preview===rtrim($baseUrl,'/').'/'.$old,'Actual avatar input preview '.$path.' '.$preview);
        $image=$request('GET',$preview);
        ensure($image->getStatusCode()===200&&$image->getContent()===$disk->get($old),'Preview GET bytes '.$path.' '.$preview);
        ensure(getimagesizefromstring($image->getContent())[0]===4,'Preview GET image '.$path);
        ensure(str_contains($render->getContent(),'showRemove'),'Removal UI configured');
        preg_match_all('/\.fileinput\((\{[^\r\n]*\})\);/',$render->getContent(),$optionMatches);
        $options=null;
        foreach ($optionMatches[1] as $json) {
            $candidate=json_decode($json,true);
            if (isset($candidate['deleteExtraData']['avatar'])) $options=$candidate;
        }
        ensure($options!==null,'Rendered avatar plugin options missing '.$path);
        $deleteUrl=$options['deleteUrl'];
        ensure(parse_url($deleteUrl,PHP_URL_PATH)==='/nested/control'.$path,'Rendered delete action '.$path.' '.$deleteUrl);
        ensure($options['deleteExtraData']['_method']==='PUT'&&$options['deleteExtraData']['_token']===$csrf,'Rendered delete method/CSRF '.$path);
        ensure($options['initialPreviewConfig'][0]['key']===0,'Rendered delete file key '.$path);
        $before=$disk->allFiles();sort($before);
        foreach (['validation','storage','database','remove-database'] as $failure) {
            $input=$data; $files=['avatar'=>$makeImage('new.png')];
            if ($failure==='validation') {$input['password']='invalid-secret';$input['password_confirmation']='mismatch';}
            if ($failure==='storage') $fault->fail='putFileAs';
            if (str_contains($failure,'database')) UploadIdentityFixture::saved(fn () => throw new RuntimeException('fixture DB failure'));
            if ($failure==='remove-database') {$input+=['avatar'=>'_file_del_','_file_del_'=>'','key'=>0];$files=[];}
            $res=$request('PUT',$path,$input,$ajax,$files);
            if ($failure==='storage') ensure($res->getStatusCode()===503&&!str_contains($res->getContent(),'fixture'),'Controlled storage error '.$path);
            elseif (str_contains($failure,'database')) ensure($res->getStatusCode()===500,'Injected DB failure '.$path.' '.$res->getStatusCode());
            else ensure($res->getStatusCode()===200&&json_decode($res->getContent(),true)['status']===false,'Invalid avatar update '.$path.' '.$res->getContent());
            $fault->fail=null;UploadIdentityFixture::flushEventListeners();
            ensure($person->fresh()->getRawOriginal('avatar')===$old,'Failure preserved old avatar '.$path.' '.$failure);
            $after=$disk->allFiles();sort($after);ensure($before===$after,'Failure leaked/deleted files '.$path.' '.$failure);
            ensure($request('GET',$preview)->getStatusCode()===200,'Failure preserved reachable preview '.$path.' '.$failure);
        }
        $bad=new Illuminate\Http\UploadedFile(__FILE__,'invalid.txt','text/plain',null,true);
        $res=$request('PUT',$path,$data,$ajax,['avatar'=>$bad]);
        ensure($res->getStatusCode()===200&&json_decode($res->getContent(),true)['status']===false,'Avatar MIME validation '.$path);
        ensure($person->fresh()->getRawOriginal('avatar')===$old,'MIME preserved avatar');
        $res=$request('PUT',$path,$data,$ajax,['avatar'=>$makeImage('replacement.png')]);
        $new=$person->fresh()->getRawOriginal('avatar');
        ensure($new!==$old&&$disk->exists($new)&&!$disk->exists($old)&&!$disk->exists(str_replace('.png','-small.png',$old)),'Replace cleanup '.$path);
        $replacementPreview=rtrim($baseUrl,'/').'/'.$new;
        ensure($request('GET',$replacementPreview)->getStatusCode()===200,'Replacement preview reachable '.$path);
        $deleteData=$options['deleteExtraData']+['key'=>$options['initialPreviewConfig'][0]['key']];
        $withoutCsrf=$deleteData;unset($withoutCsrf['_token']);
        $deniedRemoval=$request('POST',$deleteUrl,$withoutCsrf,$ajax);
        ensure($deniedRemoval->getStatusCode()===419,'Rendered remove CSRF rejection '.$path.' '.$deniedRemoval->getStatusCode().' '.substr($deniedRemoval->getContent(),0,200));
        ensure($person->fresh()->getRawOriginal('avatar')===$new&&$disk->exists($new),'Denied removal preserved avatar '.$path);
        $res=$request('POST',$deleteUrl,$deleteData,$ajax);
        ensure($res->getStatusCode()===200&&json_decode($res->getContent(),true)['status']===true,'Rendered remove JSON transport '.$path.' '.$res->getStatusCode());
        ensure($person->fresh()->getRawOriginal('avatar')===null&&!$disk->exists($new)&&!$disk->exists(str_replace('.png','-small.png',$new)),'Remove cleanup '.$path.' '.$res->getStatusCode());
        ensure($request('GET',$replacementPreview)->getStatusCode()===404,'Removed preview remains reachable '.$path);
        $journeys[]=['path'=>$path,'upload'=>true,'url'=>true,'preview_get'=>true,'preview_bytes'=>true,'rendered_delete_url'=>$deleteUrl,'rendered_remove'=>true,'remove_csrf'=>true,'validation_failure'=>true,'storage_failure'=>true,'database_failure'=>true,'remove_database_failure'=>true,'replace'=>true,'remove'=>true];
    }
    $gridData=$createData;$gridData['username']='grid-target';
    $gridCreated=$request('POST','/auth/default-users',$gridData,$ajax,['avatar'=>$makeImage('grid-avatar.png')]);
    ensure($gridCreated->getStatusCode()===200&&json_decode($gridCreated->getContent(),true)['status']===true,'Default user form avatar create');
    $gridTarget=UploadIdentityFixture::where('username','grid-target')->firstOrFail();$gridPath=$gridTarget->getRawOriginal('avatar');
    ensure($disk->exists($gridPath)&&!$disk->exists(str_replace('.png','-small.png',$gridPath)),'Known default avatar declaration');
    $context=json_decode($request('GET','/_upload-delete-context/user/'.$gridTarget->id)->getContent(),true);
    $actionData=$context['data']+['_token'=>$csrf];
    $missingToken=$context['data'];
    ensure($request('POST',$context['url'],$missingToken,$ajax)->getStatusCode()===419,'Grid delete CSRF');
    ensure($request('POST',$context['url'],array_replace($actionData,['_key'=>$actor->id]),$ajax)->getStatusCode()===403,'Grid delete context ownership');
    $cancelGrid=true;$failGrid=false;
    UploadIdentityFixture::deleting(function () use (&$cancelGrid) {return $cancelGrid?false:null;});
    UploadIdentityFixture::deleted(function () use (&$failGrid) {if ($failGrid) throw new RuntimeException('fixture private SQL credential detail');});
    $cancelled=$request('POST',$context['url'],$actionData,$ajax);
    ensure($cancelled->getStatusCode()===200&&json_decode($cancelled->getContent(),true)['status']===false,'Cancelled grid delete');
    ensure($gridTarget->fresh()!==null&&$disk->exists($gridPath),'Cancellation retained row/file');
    $cancelGrid=false;$failGrid=true;
    $failed=$request('POST',$context['url'],$actionData,$ajax);
    ensure($failed->getStatusCode()===200&&json_decode($failed->getContent(),true)['status']===false&&!str_contains($failed->getContent(),'credential'),'Generic failed grid delete');
    ensure($gridTarget->fresh()!==null&&$disk->exists($gridPath),'Failed grid delete retained row/file');$failGrid=false;
    $connection=$gridTarget->getConnection();$connection->beginTransaction();
    $pending=$request('POST',$context['url'],$actionData,$ajax);
    ensure(json_decode($pending->getContent(),true)['status']===true&&$gridTarget->fresh()===null&&$disk->exists($gridPath),'Pending grid delete commit boundary');
    $connection->rollBack();ensure($gridTarget->fresh()!==null&&$disk->exists($gridPath),'Outer rollback retained row/file');
    $deleted=$request('POST',$context['url'],$actionData,$ajax);
    ensure($deleted->getStatusCode()===200&&json_decode($deleted->getContent(),true)['status']===true,'Actual default grid action response');
    ensure($gridTarget->fresh()===null&&!$disk->exists($gridPath),'Actual default grid action row/avatar removal');
    Illuminate\Support\Facades\Schema::create('upload_host_records',function ($t) {$t->increments('id');$t->string('avatar');});
    $disk->put('images/host-owned.png','host file');$host=UploadHostFixture::create(['avatar'=>'images/host-owned.png']);
    $hostContext=json_decode($request('GET','/_upload-delete-context/host/'.$host->id)->getContent(),true);
    $hostDeleted=$request('POST',$hostContext['url'],$hostContext['data']+['_token'=>$csrf],$ajax);
    ensure(json_decode($hostDeleted->getContent(),true)['status']===true&&$host->fresh()===null&&$disk->exists('images/host-owned.png'),'Unrelated host avatar preserved');$disk->delete('images/host-owned.png');
    Illuminate\Support\Facades\Schema::table(config('admin.database.users_table'),fn ($t) => $t->softDeletes());
    $disk->put('images/soft-owned.png','soft file');$soft=UploadSoftIdentityFixture::create(['username'=>'soft-grid','name'=>'Soft grid','password'=>'unused','avatar'=>'images/soft-owned.png']);
    $softContext=json_decode($request('GET','/_upload-delete-context/soft/'.$soft->id)->getContent(),true);
    $softDeleted=$request('POST',$softContext['url'],$softContext['data']+['_token'=>$csrf],$ajax);
    ensure(json_decode($softDeleted->getContent(),true)['status']===true&&$soft->fresh()->trashed()&&$disk->exists('images/soft-owned.png'),'Soft grid delete retains avatar');$disk->delete('images/soft-owned.png');
    $gridAction=['actual_handler'=>true,'csrf'=>true,'signed_ownership'=>true,'configured_model_connection'=>true,'cancellation'=>true,'database_failure'=>true,'outer_rollback'=>true,'hard_delete_avatar_cleanup'=>true,'unrelated_host_retained'=>true,'soft_delete_retained'=>true];
    ensure($disk->allFiles()===[],'Avatar fixture residual files');
    echo json_encode(['php'=>PHP_VERSION,'laravel'=>Illuminate\Foundation\Application::VERSION,'database'=>getenv('DB_CONNECTION'),'csrf_bypass'=>false,'configured_model'=>UploadIdentityFixture::class,'connection'=>'forms','disk'=>'avatars','disk_url'=>$baseUrl,'grid_action'=>$gridAction,'journeys'=>$journeys,'browser'=>false],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL); exit(1); }
