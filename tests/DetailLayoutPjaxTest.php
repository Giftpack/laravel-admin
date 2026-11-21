<?php

use Encore\Admin\Admin;
use Encore\Admin\Show;
use Encore\Admin\Widgets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetailStepFixture extends Widgets\StepForm
{
    public function form() { $this->text('name')->rules('required'); }
    public function saveStep($data) { return $this->next($data); }
    public function retained() { return $this->all(); }
    public function finish() { $this->clear(); }
}
class WorkflowCaptureFirst extends DetailStepFixture {public static $rendered;public function render(){static::$rendered=$this;return parent::render();}}
class WorkflowCaptureLast extends DetailStepFixture {public static $rendered;public function render(){static::$rendered=$this;return parent::render();}}
class DetailExtensionFixture extends Encore\Admin\Show\AbstractField { public function render() {return 'Extended '.$this->value.' '.$this->model->id;} }
class DetailCallbackFixture { public function format($value) { return strtoupper($value); } }
class DetailLayoutPjaxTest extends TestCase
{
    protected function setUp(): void { parent::setUp();$this->app['view']->share('errors',new Illuminate\Support\ViewErrorBag()); }
    private function loginActor(): void { Encore\Admin\Facades\Admin::guard()->loginUsingId(1); }
    private function requestAt($url='/admin/details', $data=[]): Request
    {
        $request=Request::create($url,'GET',$data); $request->setLaravelSession(session()->driver());
        $this->app->instance('request',$request); return $request;
    }
    public function testShowFormattingCallbacksRelationsToolsEscapingAndMissingRecords(): void
    {
        $this->loginActor();
        $user=Tests\Models\User::first();$user->username='<script>unsafe</script>';$user->save();
        $profile=$user->profile;$profile->first_name='RelationFirst';$profile->save();
        $tag=Tests\Models\Tag::first();$tag->name='NestedTag';$tag->save(); $user->tags()->attach($tag);
        Show::extend('detailExtension',DetailExtensionFixture::class);
        $show=new Show($user, static function($show) {
            $show->field('username');
            $show->field('email')->as(static fn($v)=>strtoupper($v));
            $show->field('id')->as([new DetailCallbackFixture(),'format'])->detailExtension();
            $show->profile('Profile',static fn($child)=>$child->field('first_name'));
            $show->tags('Tags',static function($grid){$grid->column('name');$grid->disableActions();$grid->disableRowSelector();});
        });
        $html=$show->render();
        foreach (['&lt;script&gt;unsafe&lt;/script&gt;','RelationFirst','NestedTag','Extended','fa-edit','fa-trash'] as $needle) $this->assertStringContainsString($needle,$html);
        $this->assertStringNotContainsString('<script>unsafe</script>',$html);
        $this->assertStringContainsString(strtoupper($user->email),$html);
        $bound=new Show($user, function($show){ $show->field('username')->as(function($v){ return $this->id.':'.$v; }); });
        $this->assertStringContainsString($user->id.':&lt;script&gt;',$bound->render());
        $this->expectException(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        new Show(Tests\Models\User::findOrFail(999999));
    }
    public function testBuiltInShowFormattersEscapeStoredTextAndAttributesButRetainTrustedCallbacks(): void
    {
        $this->loginActor();$user=Tests\Models\User::first();$user->username='<img src=x onerror=alert(1)>';
        foreach (['label','badge','link','json'] as $method) {
            $show=new Show($user);$show->field('username')->$method();$html=$show->render();
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>',$html);$this->assertStringContainsString('&lt;img',$html);
        }
        $user->username='javascript:alert(1)';$show=new Show($user);$show->field('username')->link();$this->assertStringContainsString("href='#'",$show->render());
        $user->username="photo' onerror='bad.png";$show=new Show($user);$show->field('username')->image('https://assets.example/');$this->assertStringNotContainsString("src='https://assets.example/photo' onerror=",$show->render());
        $show=new Show($user);$show->field('username')->as(static fn()=>'<b>Trusted</b>')->label();$this->assertStringContainsString('<b>Trusted</b>',$show->render());
        $show=new Show($user);$show->panel()->tools(static fn($tools)=>$tools->disableDelete()->disableEdit()->prepend('Before')->append('After'));
        $html=$show->render();$this->assertStringNotContainsString('fa-trash',$html);$this->assertStringContainsString('Before',$html);$this->assertStringContainsString('After',$html);
        json_decode('{bad');$user->setAttribute('json_value',['text'=>'<tag>']);$show=new Show($user);$show->field('json_value')->json();$this->assertStringContainsString('&lt;tag&gt;',$show->render());
    }
    public function testLayoutsAndWidgetGalleryRenderThroughActualViews(): void
    {
        $this->loginActor();
        $tab=(new Widgets\Tab())->add('First','Tab body',true)->addLink('Second','/second');
        $gallery=[new Widgets\Alert('Alert body'), new Widgets\Callout('Callout body'),new Widgets\Box('Box title','Box body'),$tab,
            new Widgets\Table(['Name'],[['Table row']]),new Widgets\Carousel([['image'=>'/a.png','caption'=>'Carousel caption']]),
            new Widgets\InfoBox('Info title','users','green','/info','Info count'),(new Widgets\Collapse())->add('Collapse title','Collapse body')];
        $content=new Encore\Admin\Layout\Content();
        $content->title('Gallery')->description('Widgets')->row(function($row)use($gallery){foreach($gallery as $widget)$row->column(6,$widget);});
        $html=$content->render();
        foreach (['Gallery','col-md-6','Alert body','Callout body','Box body','Tab body','Table row','/a.png','Info count','Collapse body'] as $needle)$this->assertStringContainsString($needle,$html);
        $blade=$this->app['blade.compiler']->compileString('@box("Directive") Directive body @endbox');
        ob_start();eval('?>'.$blade);$directive=ob_get_clean();$this->assertStringContainsString('Directive body',$directive);
        $form=new Widgets\Form(['name'=>'Default name']);$form->text('name');$form->action('/submit?x="injected');
        $formHtml=$form->render();$this->assertStringContainsString('Default name',$formHtml);$this->assertStringContainsString('&quot;injected',$formHtml);
        $nav=new Widgets\Navbar();$nav->left('Left')->right('Right');$this->assertSame($nav->render(),$nav->render());$this->assertSame('Left',$nav->render('left'));
    }
    public function testPjaxResponseTypeStatusUnicodeTitlesAndFallbackContracts(): void
    {
        $this->loginActor();$middleware=new Encore\Admin\Middleware\Pjax();
        $request=Request::create('/admin/details?name=one');$request->headers->set('X-PJAX','true');$request->headers->set('X-PJAX-CONTAINER','#pjax-container');
        $html='<html><head><title>中文 &amp; title</title></head><body>Outside<div id="pjax-container">中文 😀 &lt;safe&gt;</div></body></html>';
        $r=$middleware->handle($request,fn()=>new Response($html,200,['Content-Type'=>'text/html; charset=UTF-8']));
        $this->assertSame('<title>中文 &amp; title</title>中文 😀 &lt;safe&gt;',$r->getContent());$this->assertSame('/admin/details?name=one',$r->headers->get('X-PJAX-URL'));
        foreach ([new Response($html,503,['Content-Type'=>'text/html']),new Illuminate\Http\JsonResponse(['ok'=>true]),new Illuminate\Http\RedirectResponse('/next'),new Symfony\Component\HttpFoundation\StreamedResponse(fn()=>print('stream')),new Response('binary',200,['Content-Type'=>'application/octet-stream'])] as $response) {
            $status=$response->getStatusCode();$this->assertSame($response,$middleware->handle($request,fn()=>$response));$this->assertSame($status,$response->getStatusCode());
        }
        foreach (['#missing','[',''] as $selector) {
            $request->headers->set('X-PJAX-CONTAINER',$selector);$r=$middleware->handle($request,fn()=>new Response($html,200,['Content-Type'=>'text/html']));$this->assertSame($html,$r->getContent());
        }
        $request->headers->set('X-PJAX-CONTAINER','#pjax-container');
        $r=$middleware->handle($request,fn()=>new Response('<div id="pjax-container">No title</div>',200,['Content-Type'=>'text/html']));$this->assertStringContainsString('No title',$r->getContent());
    }
    public function testThrowableFeedbackEscapesDebugAndConcealsProductionDetails(): void
    {
        foreach ([new RuntimeException('<script>secret</script>'),new TypeError('<script>secret</script>')] as $e) {
            config(['app.debug'=>true]);$html=Encore\Admin\Exception\Handler::renderException($e);$this->assertStringContainsString('&lt;script&gt;secret&lt;/script&gt;',$html);$this->assertStringNotContainsString('<script>secret</script>',$html);
            config(['app.debug'=>false]);$html=Encore\Admin\Exception\Handler::renderException($e);$this->assertStringContainsString(trans('admin.failed'),$html);$this->assertStringNotContainsString('secret',$html);$this->assertStringNotContainsString($e->getFile(),$html);
        }
    }
    public function testGridRecoveryRetainsExplicitResponsesAndHandlesThrowable(): void
    {
        $this->loginActor(); config(['app.debug'=>false]);
        foreach ([new RuntimeException('private-secret'),new TypeError('private-secret')] as $error) {
            $grid=new Encore\Admin\Grid(new Tests\Models\User(),function()use($error){throw $error;});
            $html=$grid->render();$this->assertStringContainsString(trans('admin.failed'),$html);$this->assertStringNotContainsString('private-secret',$html);
        }
        $grid=new Encore\Admin\Grid(new Tests\Models\User(),static function(){abort(403,'Denied');});
        try {$grid->render();$this->fail('HTTP status swallowed');}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(403,$e->getStatusCode());}
        $response=new Illuminate\Http\Response('Explicit',409);
        $grid=new Encore\Admin\Grid(new Tests\Models\User(),function()use($response){throw new Illuminate\Http\Exceptions\HttpResponseException($response);});
        try {$grid->render();$this->fail('Explicit response swallowed');}catch(Illuminate\Http\Exceptions\HttpResponseException $e){$this->assertSame($response,$e->getResponse());$this->assertSame(409,$e->getResponse()->getStatusCode());}
    }
    public function testMultistepApprovedStateAndOwnedSessionCleanup(): void
    {
        $this->loginActor(); $this->requestAt('/admin/wizard');session()->put('steps.host',['keep'=>true]);session()->put('host_key','keep');
        $first=(new DetailStepFixture(['name'=>'Constructor default']))->setSteps(['one','two'])->setCurrent('one');
        $html=$first->render();$this->assertStringContainsString('Constructor default',$html);
        preg_match('/name="_step_context"[^>]*value="([^"]+)"/',$html,$match);if (!$match)preg_match('/value="([^"]+)"[^>]*name="_step_context"/',$html,$match);$this->assertNotEmpty($match);
        $request=$this->requestAt('/admin/_handle_form_',['_step_context'=>$match[1],'_url'=>'https://evil.example','_steps'=>'evil','_current'=>'evil','name'=>'Approved']);
        $submitted=(new DetailStepFixture())->sanitize();$redirect=$submitted->saveStep(['name'=>'Approved']);$this->assertSame('http://localhost/admin/wizard?step=two',$redirect->getTargetUrl());
        $this->requestAt('/admin/other');$other=(new DetailStepFixture())->setSteps(['one','two'])->setCurrent('one');$other->saveStep(['name'=>'Other']);
        $this->requestAt('/admin/wizard');$retained=(new DetailStepFixture())->setSteps(['one','two'])->setCurrent('one');$this->assertSame(['name'=>'Approved'],$retained->data());$retained->finish();$this->assertArrayNotHasKey($match[1],session('admin_step_contexts',[]));
        $this->assertSame('keep',session('host_key'));$this->assertSame(['keep'=>true],session('steps.host'));
        $this->requestAt('/admin/other');$this->assertSame(['name'=>'Other'],$other->data());
        $this->requestAt('/admin/_handle_form_',['_step_context'=>'forged']);$this->expectException(Symfony\Component\HttpKernel\Exception\HttpException::class);(new DetailStepFixture())->sanitize();
    }
    public function testIdenticalWorkflowCopiesUseExplicitStableServerIdentifiersAcrossSteps(): void
    {
        $this->loginActor();$this->requestAt('/admin/same');
        $steps=['one'=>WorkflowCaptureFirst::class,'two'=>WorkflowCaptureLast::class];
        $before=session('admin_step_contexts',[]);
        Widgets\MultipleSteps::make($steps,'one','workflow-a')->render();$a=WorkflowCaptureFirst::$rendered;
        $aContexts=array_diff_key(session('admin_step_contexts'),$before);$afterA=session('admin_step_contexts');
        Widgets\MultipleSteps::make($steps,'one','workflow-b')->render();$b=WorkflowCaptureFirst::$rendered;
        $bContexts=array_diff_key(session('admin_step_contexts'),$afterA);
        $a->saveStep(['name'=>'A']);$b->saveStep(['name'=>'B']);
        $this->assertSame(['name'=>'A'],$a->data());$this->assertSame(['name'=>'B'],$b->data());
        $beforeLast=session('admin_step_contexts');
        Widgets\MultipleSteps::make($steps,'two','workflow-a')->render();$last=WorkflowCaptureLast::$rendered;
        $aContexts+=array_diff_key(session('admin_step_contexts'),$beforeLast);
        $this->assertSame(['name'=>'A'],$last->retained()['one']);
        $this->assertCount(2,$aContexts);$this->assertCount(1,$bContexts);
        $last->finish();$this->assertSame(['name'=>'B'],$b->data());
        foreach(array_keys($aContexts) as $token)$this->assertArrayNotHasKey($token,session('admin_step_contexts'));
        foreach(array_keys($bContexts) as $token)$this->assertArrayHasKey($token,session('admin_step_contexts'));
        Widgets\MultipleSteps::make($steps,'one','workflow-a')->render();$this->assertSame([],WorkflowCaptureFirst::$rendered->data());
        Widgets\MultipleSteps::make($steps,'two','workflow-b')->render();$this->assertSame(['name'=>'B'],WorkflowCaptureLast::$rendered->retained()['one']);
    }
    public function testTranslationsRtlAndPublishedViewOverrides(): void
    {
        $this->loginActor();
        foreach (['zh-TW','ar','en'] as $locale) {
            $this->app->setLocale($locale);config(['app.locale'=>$locale]);
            $html=(new Encore\Admin\Layout\Content())->title('中文 العربية')->body(new Widgets\Alert('Body'))->render();
            $this->assertStringContainsString('lang="'.$locale.'"',$html);$this->assertStringContainsString(trans('admin.alert'),$html);
        }
        $file=resource_path('views/vendor/admin/widgets/alert.blade.php');
        file_put_contents($file,'<aside dir="rtl">{{ $title }}: {{ $content }}</aside>');$this->app['view.engine.resolver']->resolve('blade')->getCompiler()->compile($file);$this->app['view']->getFinder()->flush();
        $this->assertSame('<aside dir="rtl">Override: العربية</aside>',(new Widgets\Alert('العربية','Override'))->render());
    }
    public function testRepeatedAndNestedRequestPresentationIsScopedWhileRegistrationsSurvive(): void
    {
        $admin=Encore\Admin\Facades\Admin::getFacadeRoot();$before=Admin::$script;Admin::script('global-script');Admin::extend('request-isolation-fixture',DetailStepFixture::class);
        $base=Admin::$baseCss;$nav=$admin->navbar();$nav->right('Global nav');
        try {
            $admin->withinRequest(function()use($admin){Admin::script('first-secret');Admin::setTitle('First');$admin->navbar()->right('First nav');
                $admin->withinRequest(function(){Admin::script('nested-secret');});$this->assertNotContains('nested-secret',Admin::$script);$this->assertContains('first-secret',Admin::$script);
                $this->assertStringContainsString('First nav',$admin->navbar()->render());
            });
            $this->assertContains('global-script',Admin::$script);$this->assertNotContains('first-secret',Admin::$script);$this->assertStringNotContainsString('First nav',$admin->navbar()->render());$this->assertSame(DetailStepFixture::class,Admin::$extensions['request-isolation-fixture']);
            config(['admin.skin'=>'skin-red']);$red=Admin::baseCss();config(['admin.skin'=>'skin-blue']);$blue=Admin::baseCss();$this->assertNotSame($red[0],$blue[0]);$this->assertSame($base,Admin::$baseCss);
            try {$admin->withinRequest(function(){Admin::script('throw-secret');throw new TypeError('test');});}catch(TypeError $e){}$this->assertNotContains('throw-secret',Admin::$script);
        } finally { Admin::$script=$before;unset(Admin::$extensions['request-isolation-fixture']); }
    }
}
