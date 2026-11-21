<?php

use Encore\Admin\Admin;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column;
use Encore\Admin\Security\HandlerContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/Fixtures/GridInteraction.php';

class GridInteractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Administrator::first(), 'admin');
        Schema::create('interaction_records',function($t){$t->increments('id');$t->string('title');$t->text('note')->nullable();$t->integer('active')->default(0);$t->integer('owner_id')->nullable();$t->string('file')->nullable();$t->json('files')->nullable();});
        Schema::create('interaction_tags',function($t){$t->increments('id');$t->string('name');});
        Schema::create('interaction_record_tags',function($t){$t->integer('record_id');$t->integer('tag_id');});
        Schema::create('interaction_other_records',function($t){$t->increments('id');$t->string('title');$t->text('note')->nullable();$t->integer('active')->default(0);$t->integer('owner_id')->nullable();});
        InteractionOtherGridRecord::create(['title'=>'Other first','note'=>'Other original']);
        InteractionRecord::create(['title'=>'First','note'=>'Original']);
        InteractionTag::insert([['name'=>'Alpha'],['name'=>'Beta']]);
        $r=$this->app['router'];
        $r->get('admin/_handle_selectable_',[Encore\Admin\Controllers\HandleController::class,'handleSelectable'])->name('admin.handle-selectable');
        $r->get('admin/_handle_renderable_',[Encore\Admin\Controllers\HandleController::class,'handleRenderable'])->name('admin.handle-renderable');
        $r->getRoutes()->refreshNameLookups();
        $r->middleware('web')->put('admin/interactions/{id}',[InteractionController::class,'update']);
        $r->middleware('web')->put('admin/other-interactions/{id}',[InteractionOtherController::class,'update']);
        $r->middleware('web')->post('admin/interactions',[InteractionController::class,'store']);
    }
    private function value($value, callable $configure): string
    {
        $model=InteractionRecord::first();$model->setAttribute('value',$value);
        $grid=new Grid(new InteractionRecord());$grid->setResource('/admin/interactions');
        $column=$grid->column('value');$configure($column);
        Column::setOriginalGridModels(collect([$model]));
        return (string) $column->fill([['value'=>$value]])[0]['value'];
    }
    public function testRegistryDisplayersHaveObservableOutputAndExtensionCallbacksBindToModels()
    {
        $cases=[
            'editable'=>['Alice', [], 'data-value="Alice"'], 'image'=>['photo.jpg', [], 'photo.jpg'],
            'label'=>['Alice', [], 'label-success'], 'button'=>['Alice', [], 'btn'], 'link'=>['Alice', ['/profile'], 'href=\'/profile\''],
            'badge'=>['Alice', [], 'badge'], 'progressBar'=>[50, [], '50%'], 'progress'=>[50, [], '50%'],
            'table'=>[[['name'=>'Alice']], [], 'Alice'], 'expand'=>['Open', [function(){return '<b>'.$this->title.'</b>';}], 'First'],
            'modal'=>['Open', ['Title', function(){return '<b>'.$this->title.'</b>';}], 'First'],
            'carousel'=>[['photo.jpg'], [], 'photo.jpg'], 'downloadable'=>['file.txt', [], 'download='],
            'copyable'=>['Alice', [], 'data-content="Alice"'], 'qrcode'=>['Alice', [], 'create-qr-code'],
            'prefix'=>['Alice', ['Hello'], 'Hello'], 'suffix'=>['Alice', ['Bye'], 'Bye'], 'secret'=>['Alice', [], '******'], 'limit'=>['Long text', [4], 'Long'],
        ];
        $this->assertEqualsCanonicalizing(array_keys(Column::$displayers), array_merge(array_keys($cases), ['orderable']));
        foreach ($cases as $method=>[$value,$args,$expected]) {
            $html=$this->value($value, fn($c)=>$c->$method(...$args));
            $this->assertStringContainsString($expected,$html,$method);
        }
        try {$this->value(1,fn($c)=>$c->orderable());$this->fail('Optional orderable integration absent');}
        catch (Exception $e) {$this->assertStringContainsString('spatie/eloquent-sortable',$e->getMessage());}
        $registry=Column::$displayers;
        try {
            Column::extend('fixtureClass', InteractionMarker::class);
            $this->assertSame('Class First:Value',$this->value('Value',fn($c)=>$c->fixtureClass('Class ')));
            Column::extend('fixtureMacro', function($value,$prefix){return $prefix.$this->title.':'.$value;});
            $this->assertSame('Row First:Value',$this->value('Value',fn($c)=>$c->fixtureMacro('Row ')));
            $this->assertSame('***',$this->value(3,fn($c)=>$c->repeat('*')));
            $this->assertSame('FIRST',$this->value('first',fn($c)=>$c->upper()));
            Column::define('value',function($value){return $this->title.':'.$value;});
            $this->assertSame('First:Value',$this->value('Value',fn($c)=>$c));
        } finally {Column::$displayers=$registry;unset(Column::$defined['value']);}
    }
    public function testDefaultAndBuiltinEscapingPreservesExplicitRawCallbacksAndEditableDomValues()
    {
        $unsafe='<img src=x onerror="probe()">';
        $this->assertSame(htmlentities($unsafe),$this->value($unsafe,fn($c)=>$c));
        $this->assertStringContainsString('data=', $this->value(null,fn($c)=>$c->qrcode()));
        $this->assertSame($unsafe,$this->value($unsafe,fn($c)=>$c->display(function($v){return $v;})));
        $this->assertStringContainsString('<b>Trusted</b>',$this->value('Value',fn($c)=>$c->display(function(){return '<b>Trusted</b>';})->label()));
        foreach (['label','badge','link','copyable','limit'] as $method) {
            $html=$this->value($unsafe,fn($c)=>$c->$method());
            $this->assertStringNotContainsString($unsafe,$html,$method);
        }
        $this->assertStringContainsString("<span class='label label-success'>Value</span>",$this->value('Value',fn($c)=>$c->label()->badge()));
        $html=$this->value("O'Reilly & \"value\"",fn($c)=>$c->editable());
        $crawler=new Symfony\Component\DomCrawler\Crawler($html);
        $this->assertSame("O'Reilly & \"value\"",$crawler->filter('a')->attr('data-value'));
        $this->assertStringContainsString("href='#'",$this->value('javascript:alert(1)',fn($c)=>$c->link()));
        $this->assertStringNotContainsString('javascript:', $this->value(['javascript://example.test/probe'],fn($c)=>$c->carousel()));
        $this->assertStringNotContainsString('javascript:', $this->value('javascript://example.test/probe',fn($c)=>$c->downloadable()));
        $this->assertStringNotContainsString("src='x' onerror",$this->value("x' onerror='probe()",fn($c)=>$c->image('/uploads')));
    }
    public function testInlineFieldsRelationsAndBasicUploadsPersistOnlyTheIntendedValueAndRejectInvalidInputs()
    {
        $record=InteractionRecord::first();
        $this->serverVariables['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'title'=>'Changed']);$this->assertResponseStatus(200);
        $this->assertSame('Changed',$record->fresh()->title);$this->assertSame('Original',$record->fresh()->note);
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'title'=>'x']);$this->assertResponseStatus(422);$this->assertSame('Changed',$record->fresh()->title);
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'active'=>1]);$this->assertResponseStatus(200);$this->assertSame(1,$record->fresh()->active);
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'owner_id'=>2]);$this->assertResponseStatus(200);$this->assertSame(2,$record->fresh()->owner_id);
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'tags'=>[1,2]]);$this->assertResponseStatus(200);$this->assertSame([1,2],$record->fresh()->tags()->orderBy('id')->pluck('id')->all());
        $this->call('POST','admin/interactions/'.$record->id,['_method'=>'PUT','_edit_inline'=>true],[],['file'=>UploadedFile::fake()->createWithContent('note.txt','fixture text')]);$this->assertResponseStatus(200);
        $path=$record->fresh()->file;$this->assertNotEmpty($path);$this->assertTrue(Illuminate\Support\Facades\Storage::disk('admin')->exists($path));
        $this->call('POST','admin/interactions/'.$record->id,['_method'=>'PUT','_edit_inline'=>true],[],['file'=>UploadedFile::fake()->create('bad.exe',1)]);$this->assertResponseStatus(422);$this->assertSame($path,$record->fresh()->file);
        $denied=Administrator::create(['username'=>'denied','name'=>'Denied','password'=>'unused']);$this->actingAs($denied,'admin');
        $this->call('PUT','admin/interactions/'.$record->id,['_edit_inline'=>true,'title'=>'Unauthorized']);$this->assertResponseStatus(403);$this->assertSame('Changed',$record->fresh()->title);
    }
    public function testInlineControlAliasesHaveDistinctPayloadsAndValuesAndCallbacksRejectStaticBinding()
    {
        foreach (['text','ip','email','url','currency','decimal','integer','textarea','datetime','date','time','year','month','day','timezone'] as $method) {
            $value = $method === 'timezone' ? 'UTC' : '2026';
            $html = $this->value($value, fn ($c) => $c->$method());
            $this->assertStringContainsString('data-name="value"', $html, $method);
            $this->assertStringContainsString('data-key="1"', $html, $method);
            $this->assertStringContainsString($value, $html, $method);
        }
        foreach (['select','radio'] as $method) {
            $html=$this->value('a',fn($c)=>$c->$method(['a'=>'Alpha','b'=>'Beta']));
            $this->assertStringContainsString('Alpha',$html);$this->assertStringContainsString('data-value="a"',$html);
        }
        foreach (['multipleSelect','checkbox'] as $method) {
            $html=$this->value(['a','b'],fn($c)=>$c->$method(['a'=>'Alpha','b'=>'Beta']));
            $this->assertStringContainsString('Alpha;Beta',$html);$this->assertStringContainsString('data-name="value"',$html);
        }
        $this->assertStringContainsString('checked',$this->value(1,fn($c)=>$c->switch()));
        $this->assertStringContainsString('grid-switch-active',$this->value(1,fn($c)=>$c->switchGroup(['active'=>'Active'])));
        foreach (['upload','uploadMany','uplaodMany'] as $method) {
            $html=$this->value('file.txt',fn($c)=>$c->$method());
            $this->assertStringContainsString('inline-upload-',$html);$this->assertStringContainsString('file.txt',$html);
        }
        foreach (['modal','expand','link','prefix','suffix'] as $method) {
            try {$this->value('Value',fn($c)=>$c->$method(static fn()=> 'invalid'));$this->fail($method.' accepted static model callback');}
            catch (InvalidArgumentException $e) {$this->assertStringContainsString('non-static',$e->getMessage());}
        }
        try {Column::extend('invalidStatic',static fn()=> 'invalid');$this->fail('Static extension accepted');}
        catch (InvalidArgumentException $e) {$this->assertStringContainsString('non-static',$e->getMessage());}
    }

    public function testDisabledDefaultBatchActionDoesNotConsumeCustomActionsOnRepeatRender()
    {
        $grid=new Grid(new InteractionRecord());
        $grid->setResource('/admin/interactions');
        $tools=new Grid\Tools\BatchActions();$tools->setGrid($grid);$tools->disableDelete();$tools->add(new InteractionBatch());
        $this->assertStringContainsString('Mark selected',$tools->render());
        $this->assertStringContainsString('Mark selected',$tools->render());
        foreach ([[new InteractionRecord(),'tag_id',1],[new InteractionOtherRecord(),'other_tag_id',2]] as [$model,$pivot,$id]) {
            $relatedGrid=new Grid($model);$column=$relatedGrid->column('tags');
            $value=[['name'=>'Related','pivot'=>[$pivot=>$id]]];$column->fill([['tags'=>$value]]);
            $probe=new InteractionManyProbe($value,$relatedGrid,$column,InteractionRecord::first());
            $this->assertSame(json_encode([$id]),$probe->originalData());
        }
    }

    public function testOverlappingGridControlsKeepLegacyClassesAndOwnUniqueIdentitiesAndResources()
    {
        Admin::$html=[];Admin::$script=[];
        $left=InteractionMultiGrid::make('left','/admin');$right=InteractionMultiGrid::make('right','/admin');
        $left->build();$right->build();
        $html=$left->render().$right->render().implode('',Admin::$html);
        $crawler=new Symfony\Component\DomCrawler\Crawler($html);
        $controls=$crawler->filter('a.ie-trigger-column-title');$this->assertCount(2,$controls);
        $this->assertNotSame($controls->eq(0)->attr('data-target'),$controls->eq(1)->attr('data-target'));
        $ids=$crawler->filter('[id]')->each(fn($node)=>$node->attr('id'));
        $this->assertSame(count($ids),count(array_unique($ids)));
        $scripts=implode('',Admin::$script);$this->assertStringContainsString('/admin/records',$scripts);$this->assertStringContainsString('/admin/other-records',$scripts);
        // Even grids sharing a resource and row/key/column receive different internal identities.
        $same=InteractionMultiGrid::make('left','/admin');$sameHtml=$same->render().implode('',Admin::$html);
        $sameControl=(new Symfony\Component\DomCrawler\Crawler($sameHtml))->filter('a.ie-trigger-column-title')->first();
        $this->assertNotSame($controls->eq(0)->attr('data-target'),$sameControl->attr('data-target'));
        $this->serverVariables['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
        $this->call('PUT','admin/interactions/1',['_edit_inline'=>true,'title'=>'Left changed']);$this->assertResponseStatus(200);
        $this->assertSame('Left changed',InteractionRecord::first()->title);$this->assertSame('Other first',InteractionOtherGridRecord::first()->title);
        $this->call('PUT','admin/other-interactions/1',['_edit_inline'=>true,'title'=>'Right changed']);$this->assertResponseStatus(200);
        $this->assertSame('Left changed',InteractionRecord::first()->title);$this->assertSame('Right changed',InteractionOtherGridRecord::first()->title);
    }

    public function testUnprojectableInteractorValidationStillRejectsBeforeTheHandlerAndNeverLeaksMessages()
    {
        $record=InteractionRecord::first();
        $secret='synthetic-private-validation-value';
        foreach ([InteractionWildcardValidation::class,InteractionForeignValidation::class] as $action) {
            InteractionWildcardValidation::$handled=0;
            $this->app->singleton($action,fn()=>new $action());
            $scope=['model'=>'InteractionRecord','key'=>(string)$record->id];
            $data=['_action'=>$action,'_model'=>'InteractionRecord','_key'=>$record->id,'_context'=>HandlerContext::issue('action',$action,$scope)];
            $this->call('POST','admin/_handle_action_',$data+['payload'=>json_encode(['declared_failure'=>true])]);$this->assertResponseStatus(200);
            $this->assertArrayHasKey('payload',json_decode($this->response->getContent(),true)['errors']);
            $this->assertSame(0,InteractionWildcardValidation::$handled);
            $badPayloads=$action===InteractionWildcardValidation::class
                ? [['rows'=>[['title'=>'x']]],['rows'=>[$secret=>['title'=>'x']]]]
                : [['foreign_value'=>'x'],['foreign_value'=>'']];
            foreach ($badPayloads as $payload) {
                $payload['credential']=$secret;
                $this->call('POST','admin/_handle_action_',$data+['payload'=>json_encode($payload)]);$this->assertResponseStatus(200);
                $body=$this->response->getContent();$response=json_decode($body,true);
                $this->assertFalse($response['status']);$this->assertSame('error',$response['toastr']['type']);
                $this->assertArrayNotHasKey('errors',$response);
                $this->assertFalse(str_contains($body,$secret));$this->assertFalse(str_contains($body,'Unsafe custom validator text'));
                $this->assertSame(0,InteractionWildcardValidation::$handled);$this->assertSame('First',$record->fresh()->title);
            }
            $valid=$action===InteractionWildcardValidation::class?['rows'=>[['title'=>'Valid']]]:['foreign_value'=>'Valid'];
            $this->call('POST','admin/_handle_action_',$data+['payload'=>json_encode($valid)]);$this->assertResponseStatus(200);
            $this->assertTrue(json_decode($this->response->getContent(),true)['status']);$this->assertSame(1,InteractionWildcardValidation::$handled);
            $this->assertSame('Validation corrected',$record->fresh()->title);$record->refresh()->update(['title'=>'First']);
        }
    }

    public function testSafeActionValidationOmitsCustomMessagesAndCredentialsThenAcceptsCorrection()
    {
        $record=InteractionRecord::first();$model='InteractionRecord';
        $data=['_action'=>'InteractionRename','_model'=>$model,'_key'=>$record->id,'_context'=>HandlerContext::issue('action',InteractionRename::class,['model'=>$model,'key'=>(string)$record->id]),'title'=>'x','password'=>'synthetic-sensitive'];
        $this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(200);
        $body=$this->response->getContent();$decoded=json_decode($body,true);
        $this->assertSame(['Use a value within the allowed size or length.'],$decoded['errors']['title']);
        $this->assertStringNotContainsString('synthetic-sensitive',$body);$this->assertStringNotContainsString('unsafe',$body);$this->assertSame('First',$record->fresh()->title);
        $data['title']='Corrected';$this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(200);$this->assertSame('Corrected',$record->fresh()->title);
        $validator=validator(['rows'=>['untrusted-secret'=>['title'=>'x']]],['rows.*.title'=>'min:3']);$validator->passes();
        $this->assertSame([],Encore\Admin\Security\SafeValidation::errors($validator,['rows.*.title']));
    }
}
