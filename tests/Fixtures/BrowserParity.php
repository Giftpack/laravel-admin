<?php
/** Synthetic issue19-only persistent browser fixture; requires the existing disposable guard. */
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
class ParityRecord extends Model {
 protected $table='parity_records';public $timestamps=false;protected $guarded=[];
 protected $casts=['payload'=>'array','rows'=>'array','settings'=>'array','files'=>'array','images'=>'array','checkbox'=>'array','checkboxButton'=>'array','checkboxCard'=>'array','multipleSelect'=>'array','listbox'=>'array','keyValue'=>'array','list'=>'array'];
 public function children(){return $this->hasMany(ParityChild::class,'parent_id');}
 public function notes(){return $this->morphMany(ParityNote::class,'subject');}
 public function tags(){return $this->belongsToMany(InteractionTag::class,'parity_tags','parent_id','tag_id');}
 public function owner(){return $this->belongsTo(InteractionTag::class,'owner_id');}
}
class ParityChild extends Model {protected $table='parity_children';public $timestamps=false;protected $guarded=[];}
class ParityNote extends Model {protected $table='parity_notes';public $timestamps=false;protected $guarded=[];}
class ParityCustomField extends Form\Field\Text {public function prepare($value){return strtoupper($value);}}
class ParityBrowserExtension extends Encore\Admin\Extension {
 protected $name='browser-parity';
 public static function boot(){if(!parent::boot())return false;Form::extend('customParity',ParityCustomField::class);Form::alias('customParity','customParityAlias');Encore\Admin\Admin::script('window.parityExtensionBooted = true;');return true;}
}
class ParityFirstStep extends Encore\Admin\Widgets\StepForm {
 public function authorize($user){return $user!==null;}
 public function form(){$this->text('name','Workflow name')->rules('required');}
 public function handle(Illuminate\Http\Request $request){return $this->next($request->only('name'));}
}
class ParityLastStep extends Encore\Admin\Widgets\StepForm {
 public function authorize($user){return $user!==null;}
 public function form(){$this->text('approved','Approval')->rules('required');}
 public function handle(Illuminate\Http\Request $request){ParityRecord::findOrFail(1)->update(['workflow'=>$this->all()['first']['name'].'/'.$request->input('approved')]);$this->clear();return redirect('/control/parity/done');}
}
class BrowserParity {
 const GROUPS=[
 'scalar'=>['text','textarea','password','hidden','email','url','ip','mobile','color','icon','number','decimal','currency','rate','slider','customParity','customParityAlias'],
 'choices'=>['checkbox','checkboxButton','checkboxCard','multipleSelect','listbox','select','radio','radioButton','radioCard','switch','tags','timezone'],
 'dates'=>['date','DateMultiple','datetime','month','year','time','dateRange','dateTimeRange','datetimeRange','timeRange'],
 'containers'=>['keyValue','list','button','display','divider','html','id'],
 ];
 static function configure($app){
 $browserRequest=Illuminate\Http\Request::capture();
 config(['cache.default'=>'file','cache.stores.file'=>['driver'=>'file','path'=>storage_path('framework/cache/data')],'admin.minify_assets'=>false,'admin.operation_log.enable'=>true,'filesystems.disks.admin.url'=>'/uploads/',
 'captcha.characters'=>['a'],'captcha.default.length'=>5,'captcha.default.width'=>120,'captcha.default.height'=>36,'captcha.default.bgImage'=>false,'captcha.default.math'=>false]);
 config(['admin.extensions.browser-parity.enable'=>true]);if(!ParityBrowserExtension::boot())throw new RuntimeException('Browser extension did not boot');
 if($browserRequest->query('locale') && in_array($browserRequest->query('locale'),['en','zh-TW','ar'])){$locale=$browserRequest->query('locale');$app->setLocale($locale);$app['translator']->addLines(Illuminate\Support\Arr::dot(['admin'=>require '/application/resources/lang/'.$locale.'/admin.php']),$locale);}
 if($browserRequest->query('rtl')){
   $css=Encore\Admin\Admin::$baseCss;
   $css=array_map(fn($p)=>str_replace(['bootstrap/css/bootstrap.min.css','dist/css/AdminLTE.min.css'],['bootstrap/css/bootstrap.rtl.min.css','dist/css/AdminLTE.rtl.min.css'],$p),$css);
   Encore\Admin\Admin::baseCss($css);
   Encore\Admin\Admin::style('body { direction: rtl; }');
 }
 if($browserRequest->query('override')){
   $dir=resource_path('views/vendor/admin/partials');@mkdir($dir,0777,true);
   view()->prependNamespace('admin',resource_path('views/vendor/admin'));
   file_put_contents($dir.'/footer.blade.php','<footer class="main-footer" id="parity-host-override">Host override 中文</footer>');
 } else {@unlink(resource_path('views/vendor/admin/partials/footer.blade.php'));}
 if($browserRequest->query('minified')){config(['admin.minify_assets'=>true]);if($browserRequest->query('rtl'))Encore\Admin\Admin::$manifest='vendor/laravel-admin/minify-rtl-manifest.json';}
 }
 static function seed(){
 Schema::create('parity_records',function($t){$t->increments('id');$t->string('title');$t->json('payload')->nullable();$t->json('rows')->nullable();$t->json('settings')->nullable();$t->integer('owner_id')->nullable();$t->string('file')->nullable();$t->string('image')->nullable();$t->json('files')->nullable();$t->json('images')->nullable();$t->string('workflow')->nullable();foreach(self::GROUPS as $aliases)foreach($aliases as $alias){if(in_array($alias,['button','display','divider','html','id']))continue;if(in_array($alias,['dateRange','dateTimeRange','datetimeRange','timeRange'])){$column=match($alias){'dateTimeRange'=>'camel_range','datetimeRange'=>'lower_range',default=>$alias};$t->text($column.'_start')->nullable();$t->text($column.'_end')->nullable();}else{$t->text($alias==='tags'?'tag_values':$alias)->nullable();}}});
 Schema::create('parity_children',function($t){$t->increments('id');$t->integer('parent_id');$t->string('label');});
 Schema::create('parity_notes',function($t){$t->increments('id');$t->integer('subject_id');$t->string('subject_type');$t->string('label');});
 Schema::create('parity_tags',function($t){$t->integer('parent_id');$t->integer('tag_id');});
 ParityRecord::create(['title'=>'Parity fixture','payload'=>[],'rows'=>[],'settings'=>[]]);
 Encore\Admin\Auth\Database\OperationLog::create(['user_id'=>1,'path'=>'parity-historical','method'=>'POST','ip'=>'127.0.0.1','input'=>json_encode(['password'=>'historical-secret','safe'=>'visible-marker'])]);
 Encore\Admin\Auth\Database\Role::create(['name'=>'Browser role','slug'=>'browser-role']);
 // Minify is the genuine optional package when the optional host is selected.
 if(class_exists(MatthiasMullie\Minify\Minify::class)){Artisan::call('admin:minify');if(!is_file(public_path(Encore\Admin\Admin::$manifest)))throw new RuntimeException('Minify manifest absent');$css=Encore\Admin\Admin::$baseCss;Encore\Admin\Admin::baseCss(array_map(fn($p)=>str_replace(['bootstrap/css/bootstrap.min.css','dist/css/AdminLTE.min.css'],['bootstrap/css/bootstrap.rtl.min.css','dist/css/AdminLTE.rtl.min.css'],$p),$css));Encore\Admin\Admin::$manifest='vendor/laravel-admin/minify-rtl-manifest.json';Encore\Admin\Admin::$min=['js'=>'vendor/laravel-admin/laravel-admin.rtl.min.js','css'=>'vendor/laravel-admin/laravel-admin.rtl.min.css'];Artisan::call('admin:minify');}
 }
 static function form($group){
 $form=new Form(new ParityRecord());$form->setAction('/control/parity/fields/'.$group);$form->saved(fn()=>redirect('/control/parity/fields/'.$group));$form->disableReset();$form->tools(fn($t)=>$t->disableDelete()->disableView()->disableList());
 foreach(self::GROUPS[$group]??[]as$alias){
  $column=match($alias){'tags'=>'tag_values','dateTimeRange'=>'camel_range','datetimeRange'=>'lower_range',default=>$alias};$label='Field '.$alias;
  if(in_array($alias,['dateRange','dateTimeRange','datetimeRange','timeRange'])){$form->$alias($column.'_start',$column.'_end',$label)->rules('required');continue;}
  if($alias==='button'){$form->button('parity_button','Parity button')->on('click',"window.parityButton=(window.parityButton||0)+1;");continue;}
  if($alias==='html'){$form->html('<strong id="parity-html">Trusted fixture HTML</strong>');continue;}
  if($alias==='divider'){$form->divider('Parity divider');continue;}
  if($alias==='display'){$form->display('title','Display fixture');continue;}
  if($alias==='id'){$form->id('id','Record identity');continue;}
  $field=$form->$alias($column,$label);
  if(in_array($alias,['checkbox','checkboxButton','checkboxCard','multipleSelect','listbox','select','radio','radioButton','radioCard']))$field->options(['0'=>'Zero','1'=>'One']);
  if($alias==='timezone')$field->default('Asia/Taipei');
  if($alias==='hidden')$field->default('hidden-browser');
  if($alias==='slider')$field->options(['min'=>0,'max'=>100])->default(10);
  if($alias==='keyValue')$field->default(['initial'=>'0']);
  if($alias==='switch')$field->default(0); else $field->rules('required');
 }
 if($group==='nested'){
  $form->text('title','Nested title')->rules('required');
  $form->embeds('settings','Embedded settings',function($f){$f->text('note','Embedded note')->rules('required');});
  $form->table('rows','Table rows',function($f){$f->text('label','Table label')->rules('required');});
  $form->hasMany('children','Child rows',function($f){$f->text('label','Child label')->rules('required');});
  $form->morphMany('notes','Morph rows',function($f){$f->text('label','Morph label')->rules('required');});
  $form->belongsTo('owner_id',InteractionPicker::class,'Owner picker');
  $form->belongsToMany('tags',InteractionPicker::class,'Tags picker');
 }
 if($group==='uploads'){
  $form->text('title','Upload title')->rules('required');
  $form->file('file','Single file')->rules('required|mimes:txt')->removable();
  $form->image('image','Single image')->rules('required|image')->removable();
  $form->multipleFile('files','Multiple files')->rules('required');
  $form->multipleImage('images','Multiple images')->rules('required');
 }
 if($group==='captcha'){$form->text('title','Captcha title')->rules('required');$form->captcha('ignored');}
 return $form;
 }
 static function routes($r){
 $r->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Bootstrap::class,Encore\Admin\Middleware\LogOperation::class,Encore\Admin\Middleware\Permission::class,Encore\Admin\Middleware\Pjax::class])->prefix('control')->group(function()use($r){
  $r->get('auth/setting',[Encore\Admin\Controllers\AuthController::class,'getSetting']);$r->put('auth/setting',[Encore\Admin\Controllers\AuthController::class,'putSetting']);
  $r->resource('auth/users',Encore\Admin\Controllers\UserController::class);$r->resource('auth/roles',Encore\Admin\Controllers\RoleController::class);$r->resource('auth/permissions',Encore\Admin\Controllers\PermissionController::class);$r->resource('auth/menu',Encore\Admin\Controllers\MenuController::class);
  $r->get('auth/logs',[Encore\Admin\Controllers\LogController::class,'index']);$r->delete('auth/logs/{id}',[Encore\Admin\Controllers\LogController::class,'destroy']);
  $r->get('parity/fields/{group}',fn($group)=>(new Content())->header('Browser fields '.$group)->body(self::form($group)->edit(1)));
  $r->put('parity/fields/{group}',fn($group)=>self::form($group)->update(1));
  $r->get('parity/state',fn()=>response()->json(['record'=>ParityRecord::with(['children','notes','tags','owner'])->find(1),'users'=>Encore\Admin\Auth\Database\Administrator::with(['roles','permissions'])->get()->map(fn($u)=>['id'=>$u->id,'username'=>$u->username,'name'=>$u->name,'avatar'=>$u->getRawOriginal('avatar'),'roles'=>$u->roles->pluck('slug'),'permissions'=>$u->permissions->pluck('slug')]),'roles'=>Encore\Admin\Auth\Database\Role::with('permissions')->get(),'logs'=>Encore\Admin\Auth\Database\OperationLog::all(),'menus'=>Encore\Admin\Auth\Database\Menu::all(),'files'=>Illuminate\Support\Facades\Storage::disk('admin')->allFiles()]));
  $r->get('parity/widgets',fn()=>(new Content())->header('Browser workflow 中文')->body(new Encore\Admin\Widgets\MultipleSteps(['first'=>ParityFirstStep::class,'last'=>ParityLastStep::class])));
  $r->get('parity/done',fn()=>(new Content())->header('Workflow persisted'));
  $r->post('_handle_form_',[Encore\Admin\Controllers\HandleController::class,'handleForm'])->name('admin.handle-form');
 });
 }
}
