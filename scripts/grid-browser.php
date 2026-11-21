<?php
// Run exclusively with scripts/start-grid-browser inside its guarded disposable database project.
require '/host/vendor/autoload.php';
require '/application/tests/Support/PackageTestHost.php';
require '/application/tests/Fixtures/GridInteraction.php';
PackageTestHost::assertDisposableEnvironment();
$root = '/tmp/laravel-admin-grid-browser';
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root.'/public'.$path) && realpath($root.'/public'.$path) && str_starts_with(realpath($root.'/public'.$path), $root.'/public/')) return false;
}
$app = PackageTestHost::create($root);
config(['app.debug'=>false,'session.driver'=>'file','session.cookie'=>'issue11_session','admin.route.prefix'=>'control','fixture.grid_audit'=>true,'admin.operation_log.enable'=>false,'admin.grid_action_class'=>Encore\Admin\Grid\Displayers\DropdownActions::class]);
$app->instance('env', 'browser-acceptance');
@mkdir($root.'/app/Admin',0777,true);
file_put_contents($root.'/app/Admin/bootstrap.php', '<?php Encore\\Admin\\Form::forget(["map","editor"]);');
config(['admin.bootstrap'=>$root.'/app/Admin/bootstrap.php']);
if (getenv('ISSUE19_BROWSER') === '1') { require '/application/tests/Fixtures/BrowserParity.php'; BrowserParity::configure($app); }
if (PHP_SAPI === 'cli' && in_array('--seed', $_SERVER['argv'] ?? [], true)) {
    @unlink($root.'/.seed-complete');
    Illuminate\Support\Facades\Schema::dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    Illuminate\Support\Facades\Artisan::call('db:seed',['--class'=>Encore\Admin\Auth\Database\AdminTablesSeeder::class,'--force'=>true]);
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-assets','--force'=>true]);
    Illuminate\Support\Facades\Schema::create('interaction_records',function($t){$t->increments('id');$t->string('title');$t->text('note')->nullable();$t->integer('active')->default(0);$t->integer('owner_id')->nullable();$t->string('file')->nullable();$t->json('files')->nullable();});
    Illuminate\Support\Facades\Schema::create('interaction_tags',function($t){$t->increments('id');$t->string('name');});
    Illuminate\Support\Facades\Schema::create('interaction_record_tags',function($t){$t->integer('record_id');$t->integer('tag_id');});
    Illuminate\Support\Facades\Schema::create('interaction_other_records',function($t){$t->increments('id');$t->string('title');$t->text('note')->nullable();$t->integer('active')->default(0);$t->integer('owner_id')->nullable();});
    InteractionOtherGridRecord::create(['title'=>'Other first','note'=>'Other original']);
    foreach(['First','Second','Protected','Fourth'] as $title) InteractionRecord::create(['title'=>$title,'note'=>'Original note']);
    InteractionTag::insert([['name'=>'Alpha'],['name'=>'Beta']]);
    $admin=Encore\Admin\Auth\Database\Administrator::first();$admin->update(['password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $user=Encore\Admin\Auth\Database\Administrator::create(['username'=>'operator','name'=>'Operator','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $denied=Encore\Admin\Auth\Database\Administrator::create(['username'=>'denied','name'=>'Denied','password'=>Illuminate\Support\Facades\Hash::make('fixture-password')]);
    $p=Encore\Admin\Auth\Database\Permission::create(['name'=>'Fixture routes','slug'=>'fixture','http_method'=>[],'http_path'=>'/*']);$user->permissions()->attach($p);$denied->permissions()->attach($p);
    if (getenv('ISSUE19_BROWSER') === '1') BrowserParity::seed();
    file_put_contents($root.'/.seed-complete','complete');
    echo "Seeded guarded browser fixture\n";return;
}
$r=$app['router'];
$r->get('/favicon.ico',fn()=>response('',204));
$r->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Bootstrap::class,Encore\Admin\Middleware\Permission::class,Encore\Admin\Middleware\Pjax::class])->prefix('control')->group(function()use($r){
    $r->get('auth/login',[Encore\Admin\Controllers\AuthController::class,'getLogin'])->name('admin.login');
    $r->post('auth/login',[Encore\Admin\Controllers\AuthController::class,'postLogin']);
    $r->post('auth/logout',[Encore\Admin\Controllers\AuthController::class,'getLogout']);
    $r->get('/',fn()=>redirect('/control/records'));
    $r->get('multi-grids',function(){return (new Encore\Admin\Layout\Content())->header('Overlapping grids')->body('<section id="multi-left">'.InteractionMultiGrid::make('left')->render().'</section><section id="multi-right">'.InteractionMultiGrid::make('right')->render().'</section>');});
    $r->put('other-records/{id}',[InteractionOtherController::class,'update']);
    $r->get('legacy-edit',function(){
        $grid=new Encore\Admin\Grid(new InteractionRecord());$grid->model()->where('id',1);$grid->setResource(url('/control/records'));$grid->disableActions();$grid->disableBatchActions();$grid->disableCreateButton();$grid->disableExport();
        $grid->column('id');$grid->column('title')->editable();$grid->column('note')->editable('textarea');
        return (new Encore\Admin\Layout\Content())->header('Legacy inline editor')->body($grid);
    });
    $r->get('fixture-file',fn()=>response()->download('/application/tests/assets/test.jpg','fixture-image.jpg'));
    $r->get('fixture-image/{id}',fn()=>response()->file('/application/tests/assets/test.jpg'));
    $r->get('gallery',function(){
        $grid=new Encore\Admin\Grid(new InteractionRecord());$grid->model()->where('id',1);$grid->disableActions();$grid->disableBatchActions();$grid->disableCreateButton();$grid->disableExport();
        $grid->column('photo')->display(function(){return url('/control/fixture-image/one');})->image();
        $grid->column('slides')->display(function(){return [url('/control/fixture-image/one'),url('/control/fixture-image/two')];})->carousel(200,160);
        $grid->column('table')->display(function(){return [['name'=>'<b>Literal table</b>','value'=>42]];})->table(['value'=>'Value','name'=>'Name']);
        $grid->column('download')->display(function(){return url('/control/fixture-file');})->downloadable();
        $grid->column('link')->display(function(){return 'Open records';})->link('/control/records','_self');
        $grid->column('qr')->qrcode(function(){return 'synthetic-grid-qr';});
        $grid->column('title')->copyable();
        $grid->column('note')->limit(5);
        $grid->column('hidden')->display(function(){return 'Synthetic hidden value';})->secret();
        return (new Encore\Admin\Layout\Content())->header('Displayer gallery')->body($grid);
    });
    $r->get('fixture-state',fn()=>response()->json(['records'=>InteractionRecord::with('tags')->get(),'other_records'=>InteractionOtherGridRecord::all(),'requests'=>is_file('/tmp/issue11-request-fields.jsonl')?array_map(fn($line)=>json_decode($line,true),file('/tmp/issue11-request-fields.jsonl',FILE_IGNORE_NEW_LINES)):[]]));
    $r->get('records',[InteractionController::class,'index']);
    $r->put('records/{id}',[InteractionController::class,'update']);
    $r->post('records',[InteractionController::class,'store']);
    $r->post('_handle_action_',[Encore\Admin\Controllers\HandleController::class,'handleAction'])->name('admin.handle-action');
    $r->get('_handle_selectable_',[Encore\Admin\Controllers\HandleController::class,'handleSelectable'])->name('admin.handle-selectable');
    $r->get('_handle_renderable_',[Encore\Admin\Controllers\HandleController::class,'handleRenderable'])->name('admin.handle-renderable');
});
if (getenv('ISSUE19_BROWSER') === '1') BrowserParity::routes($r);
$r->getRoutes()->refreshNameLookups();
$app->handleRequest(Illuminate\Http\Request::capture());
