<?php
require '/host/vendor/autoload.php';
require_once '/application/tests/Support/PackageTestHost.php';
PackageTestHost::assertDisposableEnvironment();
$mode=$argv[1]??'';
$files=match($mode){'steps'=>['Widgets/StepForm'],'navbar'=>['Widgets/Navbar'],'field'=>['Show/Field'],'assets'=>['Traits/HasAssets','Admin'],'attributes'=>['Widgets/Form'],'escaping'=>['Show/Field'],'grid'=>['Grid'],default=>[]};
foreach($files as $file) require '/application/.scratch/php85-laravel13-upgrade/issue17-before/src/'.$file.'.php';
$app=PackageTestHost::create(PackageTestHost::directory());
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
try {
 switch($mode){
 case 'steps':
  class FrozenStep extends Encore\Admin\Widgets\StepForm {public function forgetAll(){$this->clear();}public function advance(){return $this->next(['name'=>'approved']);}}
  session()->put('steps.host','host-owned');$form=new FrozenStep();$form->forgetAll();
  $lost=session('steps.host')===null;
  $req=Illuminate\Http\Request::create('/admin/_handle_form_','POST',['_url'=>'https://evil.example/redirect','_current'=>'a','_steps'=>'a,b']);$app->instance('request',$req);
  $redirect=(new FrozenStep())->sanitize()->advance()->getTargetUrl();$result=['host_owned_steps_deleted'=>$lost,'unapproved_redirect'=>$redirect];if(!$lost||!str_starts_with($redirect,'https://evil.example'))exit(1);break;
 case 'navbar':$nav=new Encore\Admin\Widgets\Navbar();$a=$nav->render();$b=$nav->render();$result=['repeat_refresh_accumulates'=>strlen($b)>strlen($a)];if(strlen($b)<=strlen($a))exit(1);break;
 case 'field':$model=new class extends Illuminate\Database\Eloquent\Model {};$model->name='value';$show=new Encore\Admin\Show($model);$field=$show->field('name')->as(static fn($value)=>'static '.$value);$field->setValue($model);try{$field->render();throw new RuntimeException('No failure');}catch(ErrorException $e){$result=['static_callback'=>$e->getMessage()];}break;
 case 'assets':$before=Encore\Admin\Admin::$baseCss;Encore\Admin\Admin::baseCss();Encore\Admin\Admin::baseCss();$result=['base_css_grows'=>count(Encore\Admin\Admin::$baseCss)-count($before)];if($result['base_css_grows']!==2)exit(1);break;
 case 'grid':config(['app.debug'=>false]);$model=new class extends Illuminate\Database\Eloquent\Model {};$grid=new Encore\Admin\Grid($model,static function(){abort(403,'original-denied');});$html=$grid->render();$result=['http403_swallowed_into_rendered_feedback'=>is_string($html)&&str_contains($html,__('admin.failed'))];if(!$result['http403_swallowed_into_rendered_feedback'])throw new RuntimeException('Frozen grid output: '.$html);break;
 case 'escaping':$model=new class extends Illuminate\Database\Eloquent\Model {};$model->name='<img src=x onerror=alert(1)>';$show=new Encore\Admin\Show($model);$show->field('name')->label();$html=$show->render();$result=['stored_label_html_unescaped'=>str_contains($html,'<img src=x onerror=alert(1)>')];if(!$result['stored_label_html_unescaped'])exit(1);break;
 case 'attributes':$html=(new Encore\Admin\Widgets\Form())->action('/url?x=" onsubmit="injected')->formatAttribute();$result=['raw_attribute_injection'=>str_contains($html,'onsubmit="injected')];if(!$result['raw_attribute_injection'])exit(1);break;
 default:throw new RuntimeException('Missing mode');
 }
 echo json_encode(['php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'mode'=>$mode,'observed'=>$result,'source_hashes'=>array_map(fn($f)=>hash_file('sha256','/application/.scratch/php85-laravel13-upgrade/issue17-before/src/'.$f.'.php'),$files)],JSON_PRETTY_PRINT).PHP_EOL;
}finally{restore_error_handler();}
