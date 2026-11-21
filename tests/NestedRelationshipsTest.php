<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Fixtures/NestedRelationshipModels.php';

class NestedRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->be(Administrator::first(),'admin');
        view()->share('errors',new Illuminate\Support\ViewErrorBag());
        app('router')->get('admin/_handle_selectable_',[Encore\Admin\Controllers\HandleController::class,'handleSelectable'])->name('admin.handle-selectable');
        app('router')->getRoutes()->refreshNameLookups();
        Schema::create('nested_tags',function($t) { $t->string('tag_code')->primary(); $t->string('label'); $t->boolean('visible')->default(true); });
        Schema::create('nested_parents',function($t) { $t->string('code')->primary(); $t->string('title')->nullable(); $t->string('owner_code')->nullable(); $t->json('settings')->nullable(); $t->json('rows')->nullable(); });
        Schema::create('nested_children',function($t) { $t->string('child_code')->primary(); $t->string('parent_code')->nullable(); $t->string('label'); $t->integer('position')->default(0); $t->string('locked')->nullable(); $t->json('meta')->nullable(); });
        Schema::create('nested_notes',function($t) { $t->increments('id'); $t->string('subject_id')->nullable(); $t->string('subject_type')->nullable(); $t->string('label'); $t->integer('position')->default(0); });
        Schema::create('nested_pivots',function($t) { $t->string('parent_code'); $t->string('tag_code'); $t->integer('weight')->default(0); $t->unique(['parent_code','tag_code']); $t->foreign('tag_code')->references('tag_code')->on('nested_tags'); });
        NestedTag::insert([['tag_code'=>'t0','label'=>'Zero','visible'=>1],['tag_code'=>'t1','label'=>'One','visible'=>1],['tag_code'=>'hidden','label'=>'Restricted','visible'=>0]]);
    }
    private function input(array $data, string $method='POST',array $server=[]): void
    {
        $r=Request::create('/admin/nested',$method,$data,[],[],['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']+$server); $r->setLaravelSession(app('session.store')); app()->instance('request',$r); Illuminate\Support\Facades\Request::swap($r);
    }
    private function form(): Form
    {
        $f=new Form(new NestedParent()); $f->hidden('code'); $f->text('title');
        $f->belongsTo('owner_code',NestedTagPicker::class)->rules('nullable|exists:nested_tags,tag_code');
        $f->belongsToMany('tags',NestedTagPicker::class);
        $f->embeds('settings',function($e) { $e->text('name')->rules('required'); $e->text('keep'); $e->text('locked')->readonly(); $e->password('secret'); });
        $f->table('rows',function($n) { $n->text('label')->rules('required'); $n->number('position'); });
        $f->hasMany('children',function($n) { $n->hidden('child_code'); $n->text('label')->rules('required'); $n->number('position'); $n->text('locked')->readonly(); $n->embeds('meta',function($e) { $e->text('name')->rules('required'); }); });
        $f->morphMany('notes',function($n) { $n->text('label')->rules('required'); $n->number('position'); });
        return $f;
    }
    private function write(array $data,?string $key=null): Form
    {
        $this->input($data,$key===null?'POST':'PUT'); $f=$this->form(); $response=$key===null?$f->store():$f->update($key); $this->assertTrue($response->getData(true)['status'],json_encode($response->getData(true))); return $f;
    }
    public function testNestedCreatePartialUpdateRemoveAndExactReload(): void
    {
        $this->write(['code'=>'p0','title'=>'before','owner_code'=>'t0','tags'=>['','t0','t1'], 'settings'=>['name'=>'N','keep'=>'K','locked'=>'forged','unknown'=>'forged'], 'rows'=>['new_4'=>['label'=>'A','position'=>0], 'new_8'=>['label'=>'B','position'=>2]], 'children'=>['new_4'=>['child_code'=>'c0','label'=>'C','position'=>1,'locked'=>'forged','meta'=>['name'=>'JSON']]], 'notes'=>['new_8'=>['label'=>'M','position'=>1]]]);
        $p=NestedParent::findOrFail('p0'); $this->assertSame('p0',$p->children->first()->parent_code); $this->assertSame(['name'=>'JSON'],$p->children->first()->meta); $this->assertNull($p->children->first()->locked);
        $this->assertSame('p0',$p->notes->first()->subject_id); $this->assertSame(NestedParent::class,$p->notes->first()->subject_type); $this->assertSame('t0',$p->owner->getKey());
        $this->assertEquals(['name'=>'N','keep'=>'K'],$p->settings); $this->assertSame(['new_4'=>['label'=>'A','position'=>0],'new_8'=>['label'=>'B','position'=>2]],$p->rows);
        $noteId=$p->notes->first()->id;
        $this->write(['title'=>'partial'],'p0'); $this->assertSame('C',$p->fresh()->children->first()->label); $this->assertSame(2,$p->fresh()->tags->count());
        $this->write(['settings'=>['name'=>'Updated'], 'children'=>['c0'=>['child_code'=>'c0','label'=>'Changed','position'=>2], 'new_9'=>['child_code'=>'c1','label'=>'New','position'=>1]], 'notes'=>[$noteId=>['id'=>$noteId,'_remove_'=>1]], 'tags'=>['t1']], 'p0');
        $p=$p->fresh(); $this->assertEquals(['name'=>'Updated','keep'=>'K'],$p->settings); $this->assertSame(['c1','c0'],$p->children->modelKeys()); $this->assertSame(0,$p->notes->count()); $this->assertSame(['t1'],$p->tags->modelKeys());
        $this->assertNotNull(NestedTag::find('t0'));
        $form=$this->form(); $form->edit('p0'); $fields=$form->fields()->keyBy(fn($field)=>$field->column());
        $this->assertSame($p->rows,$fields['rows']->value()); $this->assertSame($p->settings,$fields['settings']->value());
        $grid=new Grid(new NestedChild()); $grid->setRelation($p->children()); $grid->column('child_code'); $grid->column('label'); $grid->column('position');
        $data=$grid->model()->buildData(); $this->assertSame(['c1','c0'],array_column($data,'child_code')); $this->assertSame(['New','Changed'],array_column($data,'label')); $this->assertSame([1,2],array_column($data,'position'));
        $this->assertSame(['name'=>'JSON'], $data[1]['meta']);
        $this->write(['children'=>['c0'=>['child_code'=>'c0','_remove_'=>1]]],'p0'); $this->assertNull(NestedChild::find('c0')); $this->assertNotNull(NestedChild::find('c1'));
    }
    public function testForeignAndDuplicateChildrenRollbackParentAndEarlierRelatedWrites(): void
    {
        NestedParent::create(['code'=>'other']); NestedChild::create(['child_code'=>'foreign','parent_code'=>'other','label'=>'owned']);
        $this->write(['code'=>'p','title'=>'before']);
        foreach ([['a'=>['child_code'=>'foreign','label'=>'stolen']], ['new_1'=>['child_code'=>'new','label'=>'A'],'new_2'=>['child_code'=>'new','label'=>'B']]] as $rows) {
            $this->input(['title'=>'after','tags'=>['t0'],'children'=>$rows],'PUT');
            try { $this->form()->update('p'); $this->fail('Expected rejected reference'); } catch (Illuminate\Database\Eloquent\ModelNotFoundException|InvalidArgumentException $e) {}
            $this->assertSame('before',NestedParent::find('p')->title); $this->assertSame(0,DB::table('nested_pivots')->count()); $this->assertSame('other',NestedChild::find('foreign')->parent_code); $this->assertNull(NestedChild::find('new'));
        }
    }
    public function testChildFailureRollsBackParentPivotAndEarlierChildOnCreateAndUpdate(): void
    {
        foreach ([null,'p'] as $key) {
            if ($key) NestedParent::create(['code'=>'p','title'=>'before']);
            NestedChild::saved(function($child) { if ($child->child_code==='fail') throw new RuntimeException('child SQL failure'); });
            $this->input(['code'=>'p','title'=>'after','tags'=>['t0'],'children'=>['new_1'=>['child_code'=>'ok','label'=>'OK'],'new_2'=>['child_code'=>'fail','label'=>'Fail']]],$key?'PUT':'POST');
            try { $f=$this->form(); $key?$f->update($key):$f->store(); $this->fail('Expected child failure'); } catch (RuntimeException $e) { $this->assertSame('child SQL failure',$e->getMessage()); } finally { NestedChild::flushEventListeners(); }
            $this->assertSame($key?'before':null,NestedParent::find('p')?->title); $this->assertSame(0,NestedChild::count()); $this->assertSame(0,DB::table('nested_pivots')->count());
        }
    }
    public function testMalformedSpecializedInputsAndValidationProjection(): void
    {
        foreach (['children'=>'bad','notes'=>['bad'],'rows'=>['new_1'=>['label'=>['bad']]],'settings'=>['name'=>['bad']]] as $column=>$value) {
            $this->input(['code'=>'p',$column=>$value]); $response=$this->form()->store(); $this->assertFalse($response->getData(true)['status']); $this->assertSame(0,NestedParent::count());
        }
        $this->input(['code'=>'p','children'=>['new_7'=>['child_code'=>'c','label'=>'','meta'=>['name'=>'']]],'rows'=>['new_8'=>['label'=>'']],'settings'=>['name'=>'']]);
        $errors=$this->form()->validationMessages(request()->all());
        foreach (['children.new_7.label','children.new_7.meta.name','rows.new_8.label','settings.name'] as $key) $this->assertTrue($errors->has($key),$key);
        $this->input(['code'=>'p','children'=>['new_7'=>['_remove_'=>1]],'rows'=>['new_8'=>['_remove_'=>1]]]); $this->assertFalse($this->form()->validationMessages(request()->all()));
    }
    public function testPivotAttributesOrderDetachAndSqlFailureRollback(): void
    {
        NestedParent::create(['code'=>'p','title'=>'before']);
        $make=function () { $f=new Form(new NestedParent()); $f->text('title'); $f->table('tags',function($n) { $n->number('weight')->rules('required|integer'); }); return $f; };
        $this->input(['title'=>'after','tags'=>['t0'=>['weight'=>3],'t1'=>['weight'=>1]]],'PUT');
        $this->assertTrue($make()->update('p')->getData(true)['status']);
        $p=NestedParent::find('p'); $this->assertSame(['t1','t0'],$p->tags->modelKeys()); $this->assertSame([1,3],$p->tags->pluck('pivot.weight')->all());
        $grid=new Grid(new NestedTag()); $grid->setRelation($p->tags()); $grid->column('label')->display(fn($value)=>strtoupper($value)); $related=$grid->model()->buildData(); $this->assertSame(['t1','t0'],array_column($related,'tag_code')); $this->assertSame([1,3],array_column(array_column($related,'pivot'),'weight'));
        $f=$make(); $f->edit('p'); $html=(string)$f->fields()->last()->render(); $this->assertStringContainsString('tags[t1][weight]',$html);
        $this->input(['title'=>'rolled back','tags'=>['t0'=>['weight'=>null]]],'PUT');
        // Remove the required rule for a real database NOT NULL failure during sync.
        $f=new Form(new NestedParent()); $f->text('title'); $f->table('tags',fn($n)=>$n->number('weight'));
        try { $f->update('p'); $this->fail('Expected pivot SQL failure'); } catch (Illuminate\Database\QueryException $e) {}
        $this->assertSame('after',$p->fresh()->title); $this->assertSame(['t1','t0'],$p->fresh()->tags->modelKeys()); $this->assertSame([1,3],$p->fresh()->tags->pluck('pivot.weight')->all());
        $this->input(['tags'=>[]],'PUT'); $this->assertTrue($make()->update('p')->getData(true)['status']); $this->assertSame(0,$p->fresh()->tags->count()); $this->assertSame(3,NestedTag::count());
    }
    public function testPickerRejectsMissingHiddenAndDuplicateReferencesAndPreservesOmittedSelection(): void
    {
        NestedTag::create(['tag_code'=>'0','label'=>'Zero key','visible'=>true]);
        $this->write(['code'=>'zero','owner_code'=>'0']);
        $zero=$this->form(); $zero->edit('zero'); $zeroField=$zero->fields()->first(fn($field)=>$field->column()==='owner_code');
        $this->assertStringContainsString('value="0"',(string)$zeroField->render()); $this->assertSame('0',NestedParent::find('zero')->owner->getKey());
        $this->write(['code'=>'p','owner_code'=>'t0','tags'=>['t0']]);
        foreach ([['owner_code'=>'missing'],['owner_code'=>'hidden'],['tags'=>['t0','t0']],['tags'=>['missing']],['tags'=>['hidden']]] as $data) {
            $this->input(['title'=>'forged']+$data,'PUT'); $r=$this->form()->update('p'); $this->assertFalse($r->getData(true)['status']); $this->assertNull(NestedParent::find('p')->title); $this->assertSame(['t0'],NestedParent::find('p')->tags->modelKeys());
        }
        NestedTag::whereKey('t0')->update(['visible'=>false]);
        $this->write(['title'=>'omitted'],'p'); $this->assertSame('t0',NestedParent::find('p')->owner_code);
        $f=$this->form(); $f->edit('p'); $fields=$f->fields()->keyBy(fn($field)=>$field->column());
        $this->assertStringNotContainsString('Zero',(string)$fields['owner_code']->render());
        $this->assertStringNotContainsString('Zero',(string)$fields['tags']->render());
        $this->write(['owner_code'=>null,'tags'=>[]],'p'); $this->assertNull(NestedParent::find('p')->owner_code); $this->assertSame(0,NestedParent::find('p')->tags->count());
    }
    public function testNestedRedirectErrorsStableKeysDefaultsAndAssetsRender(): void
    {
        $make=function () {
            $f=$this->form(); $f->fields()->first(fn($field)=>$field->column()==='rows')->default(['new_5'=>['label'=>'Default row','position'=>0]]);
            return $f;
        };
        app('router')->middleware('web')->get('/nested-errors',function () use($make) { $html=''; foreach($make()->fields() as $field) $html.=(string)$field->render(); return $html; });
        app('router')->middleware('web')->post('/nested-errors',fn()=>$make()->store());
        foreach ([[],['HTTP_X_PJAX'=>'true','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']] as $server) {
            $this->call('POST','/nested-errors',['code'=>'p','title'=>'Sibling','settings'=>['name'=>'','secret'=>'nested-secret'],'children'=>['new_17'=>['child_code'=>'c','label'=>'','meta'=>['name'=>'Valid']]],'rows'=>['new_22'=>['label'=>['bad']]]],[],[],['HTTP_REFERER'=>'http://localhost/nested-errors']+$server);
            $this->assertResponseStatus(302); $this->assertNull(session()->getOldInput('settings.secret')); $this->assertNull(session()->getOldInput('rows.new_22.label')); $this->assertSame('Sibling',session()->getOldInput('title'));
            $this->call('GET','/nested-errors',[],[],[],$server); $this->assertResponseStatus(200); $html=$this->response->getContent();
            foreach (['children[new_17][label]','children[new_17][meta][name]','has-error','Sibling'] as $text) $this->assertStringContainsString($text,$html);
            $this->assertStringNotContainsString('nested-secret',$html);
        }
        session()->forget('_old_input'); view()->share('errors',new Illuminate\Support\ViewErrorBag());
        $this->input([]); $f=$make(); $fields=$f->fields()->keyBy(fn($field)=>$field->column());
        $html=(string)$fields['rows']->render(); $this->assertStringContainsString('Default row',$html); $this->assertStringContainsString('rows[new_5][label]',$html);
        foreach (['default','tab','table'] as $mode) { $fields['children']->mode($mode); $this->assertStringContainsString('__LA_KEY__',(string)$fields['children']->render()); }
        $this->assertStringContainsString('select2',implode(' ',Form::collectFieldAssets()['js']));
        $scripts=implode(' ',Encore\Admin\Admin::$script); $this->assertStringContainsString('Math.max(index',$scripts); $this->assertStringContainsString('_context=',$scripts);
    }

    public function testHasManySelectionDetachesWithoutDeletingAndRejectsForeignOwnership(): void
    {
        NestedParent::insert([['code'=>'p'],['code'=>'other']]);
        NestedChild::insert([['child_code'=>'old','parent_code'=>'p','label'=>'Old'],['child_code'=>'orphan','parent_code'=>null,'label'=>'Orphan'],['child_code'=>'foreign','parent_code'=>'other','label'=>'Foreign']]);
        $make=function() { $f=new Form(new NestedParent()); $f->text('title'); $f->multipleSelect('children'); return $f; };
        $this->input(['children'=>['orphan']],'PUT'); $this->assertTrue($make()->update('p')->getData(true)['status']);
        $this->assertNull(NestedChild::find('old')->parent_code); $this->assertSame('p',NestedChild::find('orphan')->parent_code); $this->assertSame(3,NestedChild::count());
        foreach ([['foreign'],['missing'],['orphan','orphan']] as $ids) {
            $this->input(['title'=>'forged','children'=>$ids],'PUT');
            try { $make()->update('p'); $this->fail('Expected invalid selection'); } catch (InvalidArgumentException $e) {}
            $this->assertNull(NestedParent::find('p')->title); $this->assertSame('p',NestedChild::find('orphan')->parent_code); $this->assertSame('other',NestedChild::find('foreign')->parent_code);
        }
    }
    public function testCrossConnectionWriteIsRejectedAndParentTransactionRollsBack(): void
    {
        config(['database.connections.other'=>config('database.connections.'.config('database.default'))]);
        NestedParent::create(['code'=>'p','title'=>'before']); NestedChild::create(['child_code'=>'c','parent_code'=>'p','label'=>'Before']);
        $this->input(['title'=>'after','children'=>['c'=>['child_code'=>'c','label'=>'After']]],'PUT');
        $f=new Form(new NestedOtherParent()); $f->text('title'); $f->hasMany('children',function($n) { $n->hidden('child_code'); $n->text('label'); });
        try { $f->update('p'); $this->fail('Expected cross-connection rejection'); } catch (LogicException $e) { $this->assertSame('Form relationship writes require the parent connection.',$e->getMessage()); }
        $this->assertSame('before',NestedParent::find('p')->title); $this->assertSame('Before',NestedChild::find('c')->label);
    }
    public function testActualPickerProducerSignedEndpointAndAuthorization(): void
    {
        $this->input([]); Encore\Admin\Admin::$script=[];
        $f=$this->form(); $fields=$f->fields()->keyBy(fn($field)=>$field->column()); (string)$fields['owner_code']->render();
        $scripts=implode(' ',Encore\Admin\Admin::$script); $this->assertSame(1,preg_match('/load\("([^"\n]+_context=[^"\n]+)"\)/',$scripts,$matches));
        $url=html_entity_decode($matches[1]); $this->call('GET',$url); $this->assertResponseStatus(200); $this->assertStringContainsString('Zero',$this->response->getContent()); $this->assertStringNotContainsString('Restricted',$this->response->getContent());
        parse_str(parse_url($url,PHP_URL_QUERY),$args); $args['_context']='forged'; $this->call('GET','/admin/_handle_selectable_',$args); $this->assertResponseStatus(403);
        $this->input(['owner_code'=>'t0']); $denied=new Form(new NestedParent()); $denied->belongsTo('owner_code',NestedDeniedPicker::class); $this->assertTrue($denied->validationMessages(request()->all())->has('owner_code'));
        $this->input([]); $source=$f->select('source')->options(['t0'=>'Zero'])->load('target','/dependent'); $target=$f->select('target')->options(fn($value)=>[$value=>'Current'])->value('t0');
        $this->assertStringContainsString('name="source"',(string)$source->render()); $this->assertStringContainsString('/dependent',implode(' ',Encore\Admin\Admin::$script)); $this->assertStringContainsString('Current',(string)$target->render());
    }

    public function testRequiredSpecializedContainersAndDefaultsNeverImplicitlyWrite(): void
    {
        foreach (['rows'=>'table','settings'=>'embeds','children'=>'hasMany','notes'=>'morphMany'] as $column=>$type) {
            $f=new Form(new NestedParent()); $f->hidden('code'); $f->$type($column,fn($n)=>$n->text('label'))->rules('required');
            $this->input(['code'=>'p']); $this->assertFalse($f->store()->getData(true)['status']); $this->assertSame(0,NestedParent::count());
            $this->input(['code'=>'p',$column=>[]]); $this->assertFalse($f->store()->getData(true)['status']); $this->assertSame(0,NestedParent::count());
        }
        $this->input(['code'=>'p']); $f=$this->form();
        foreach ($f->fields() as $field) if (in_array($field->column(),['rows','settings'])) $field->default(['ui'=>'only']);
        $this->assertTrue($f->store()->getData(true)['status']); $this->assertNull(NestedParent::find('p')->settings); $this->assertNull(NestedParent::find('p')->rows);
    }

    public function testCancelledChildSaveRollsBackParentAndPivot(): void
    {
        $this->write(['code'=>'p','title'=>'before']);
        NestedChild::saving(fn()=>false);
        $this->input(['title'=>'after','tags'=>['t0'],'children'=>['new_1'=>['child_code'=>'c','label'=>'Cancelled']]],'PUT');
        try { $this->form()->update('p'); $this->fail('Expected cancelled child failure'); } catch (RuntimeException $e) { $this->assertSame('Related record could not be saved.',$e->getMessage()); } finally { NestedChild::flushEventListeners(); }
        $this->assertSame('before',NestedParent::find('p')->title); $this->assertSame(0,NestedChild::count()); $this->assertSame(0,DB::table('nested_pivots')->count());
    }

    public function testCancelledCustomPivotInsertAndUpdateRollBackParent(): void
    {
        NestedParent::create(['code'=>'p','title'=>'before']);
        $make=function() { $f=new Form(new NestedPivotParent()); $f->text('title'); $f->table('tags',fn($n)=>$n->number('weight')); return $f; };
        foreach ([false,true] as $existing) {
            if ($existing) DB::table('nested_pivots')->insert(['parent_code'=>'p','tag_code'=>'t0','weight'=>1]);
            NestedPivot::saving(fn()=>false);
            $this->input(['title'=>'after','tags'=>['t0'=>['weight'=>3]]],'PUT');
            try { $make()->update('p'); $this->fail('Expected cancelled pivot failure'); } catch (RuntimeException $e) { $this->assertSame('Related pivot could not be saved.',$e->getMessage()); } finally { NestedPivot::flushEventListeners(); }
            $this->assertSame('before',NestedParent::find('p')->title); $this->assertSame($existing?1:0,DB::table('nested_pivots')->count());
            if ($existing) $this->assertSame(1,DB::table('nested_pivots')->value('weight'));
        }
    }

    public function testNewRowsOnExistingParentRequireMissingFieldsAndProjectRedirectErrors(): void
    {
        NestedParent::create(['code'=>'p','title'=>'Before']);
        NestedChild::create(['child_code'=>'old','parent_code'=>'p','label'=>'Keep']);
        $make=function() { $f=new Form(new NestedParent()); $f->text('title');
            $f->hasMany('children',function($n) { $n->hidden('child_code'); $n->text('label')->creationRules('required')->updateRules('nullable'); $n->number('position'); });
            $f->morphMany('notes',fn($n)=>$n->text('label')->rules('required'));
            $f->table('rows',function($n){$n->text('label')->rules('required');$n->number('position');});
            return $f;
        };
        foreach (['children','notes','rows'] as $column) {
            $this->input(['title'=>'Rejected',$column=>['new_11'=>['child_code'=>'review-child']]],'PUT');
            $response=$make()->update('p'); $this->assertFalse($response->getData(true)['status'],$column);
            $this->assertArrayHasKey($column.'.new_11.label',$response->getData(true)['validation']);
            $this->assertSame('Before',NestedParent::find('p')->title); $this->assertSame(1,NestedChild::count()); $this->assertSame(0,NestedNote::count()); $this->assertNull(NestedParent::find('p')->rows);
        }
        $this->input(['children'=>['old'=>['child_code'=>'old','position'=>2]]],'PUT'); $this->assertTrue($make()->update('p')->getData(true)['status']); $this->assertSame('Keep',NestedChild::find('old')->label);
        NestedParent::find('p')->update(['rows'=>['existing'=>['label'=>'Keep row','position'=>1]]]);
        $this->input(['rows'=>['existing'=>['position'=>2]]],'PUT'); $this->assertTrue($make()->update('p')->getData(true)['status']); $this->assertEquals(['existing'=>['label'=>'Keep row','position'=>2]],NestedParent::find('p')->rows);
        $note=NestedParent::find('p')->notes()->create(['label'=>'Keep note','position'=>1]);
        $this->input(['notes'=>[$note->id=>['id'=>$note->id,'position'=>2]]],'PUT'); $this->assertTrue($make()->update('p')->getData(true)['status']); $this->assertSame('Keep note',$note->fresh()->label);
        app('router')->middleware('web')->put('/nested-required',fn()=>$make()->update('p'));
        app('router')->middleware('web')->get('/nested-required',function()use($make){$f=$make();$f->edit('p');$html='';foreach($f->fields() as $field)$html.=(string)$field->render();return $html;});
        foreach ([[],['HTTP_X_PJAX'=>'true','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']] as $server) {
            $this->call('PUT','/nested-required',['title'=>'Sibling','children'=>['new_17'=>['child_code'=>'review-child']]],[],[],['HTTP_REFERER'=>'http://localhost/nested-required']+$server);
            $this->assertResponseStatus(302); $this->assertTrue(session('errors')->has('children.new_17.label'));
            $this->call('GET','/nested-required',[],[],[],$server); $this->assertResponseStatus(200);
            foreach (['children[new_17][label]','has-error','Sibling'] as $text) $this->assertStringContainsString($text,$this->response->getContent());
            $this->assertSame('Before',NestedParent::find('p')->title); $this->assertSame(1,NestedChild::count());
        }
    }
    public function testPivotReadbackAcceptsEquivalentCastsAndMutators(): void
    {
        Schema::table('nested_pivots',function($t){$t->date('day')->nullable();$t->boolean('flag')->default(false);$t->decimal('amount',8,2)->nullable();$t->json('payload')->nullable();$t->string('note')->nullable();});
        NestedParent::create(['code'=>'p','title'=>'Before']);
        $make=function() { $f=new Form(new NestedCastPivotParent()); $f->text('title'); $f->table('tags',function($n){$n->text('day');$n->hidden('flag');$n->text('amount');$n->embeds('payload',fn($e)=>$e->text('name'));$n->text('note');}); return $f; };
        foreach (['2026-11-21','2026-11-22'] as $day) {
            $this->input(['title'=>'After','tags'=>['t0'=>['day'=>$day,'flag'=>'1','amount'=>'12.3','payload'=>['name'=>'JSON'],'note'=>'Mixed CASE']]],'PUT');
            $this->assertTrue($make()->update('p')->getData(true)['status']);
            $pivot=NestedCastPivotParent::find('p')->tags->first()->pivot;
            $this->assertSame($day,$pivot->day->format('Y-m-d')); $this->assertTrue($pivot->flag); $this->assertSame('12.30',$pivot->amount); $this->assertSame(['name'=>'JSON'],$pivot->payload); $this->assertSame('mixed case',$pivot->note); $this->assertSame('After',NestedParent::find('p')->title);
        }
    }

    public function testTableExplicitArraysReplaceWhileOmittedColumnsRemain(): void
    {
        NestedParent::create(['code'=>'p','rows'=>['old'=>['label'=>'Keep','values'=>['a','b'],'settings'=>['name'=>'Old','keep'=>'K'],'detail'=>['left'=>'L','right'=>'R']]]]);
        $make=function () {
            $form=new Form(new NestedParent());
            $form->table('rows',function ($row) {
                $row->text('label')->rules('required');
                $row->multipleSelect('values')->options(['a'=>'A','b'=>'B']);
                $row->embeds('settings',function ($embedded) { $embedded->text('name'); $embedded->text('keep'); });
                $row->text('detail.left'); $row->text('detail.right');
            });
            return $form;
        };
        foreach ([['a'],[]] as $values) {
            $this->input(['rows'=>['old'=>['values'=>$values,'settings'=>['name'=>'New'],'detail'=>['left'=>'Changed']]]],'PUT');
            $this->assertTrue($make()->update('p')->getData(true)['status']);
            $row=NestedParent::find('p')->rows['old'];
            $this->assertSame($values,$row['values']);
            $this->assertSame('Keep',$row['label']);
            $this->assertEquals(['name'=>'New','keep'=>'K'],$row['settings']);
            $this->assertEquals(['left'=>'Changed','right'=>'R'],$row['detail']);
        }
    }

    public function testMissingHiddenPrimaryKeyUsesSameScopedRowIdentityForValidationAndPersistence(): void
    {
        NestedParent::create(['code'=>'p']);
        NestedChild::create(['child_code'=>'old','parent_code'=>'p','label'=>'Keep']);
        $note=NestedParent::find('p')->notes()->create(['label'=>'Keep note','position'=>0]);
        $make=function () {
            $form=new Form(new NestedParent());
            $form->hasMany('children',function ($row) { $row->text('label')->rules('required'); $row->number('position'); });
            $form->morphMany('notes',function ($row) { $row->text('label')->rules('required'); $row->number('position'); });
            return $form;
        };
        foreach ([[],['child_code'=>'']] as $identity) {
            $this->input(['children'=>['old'=>$identity+['position'=>2]],'notes'=>[$note->id=>['position'=>3]]],'PUT');
            $this->assertTrue($make()->update('p')->getData(true)['status']);
            $this->assertSame(1,NestedChild::count()); $this->assertSame(1,NestedNote::count());
            $this->assertSame('Keep',NestedChild::find('old')->label); $this->assertSame(2,NestedChild::find('old')->position);
            $this->assertSame('Keep note',$note->fresh()->label); $this->assertSame(3,$note->fresh()->position);
        }
        foreach (['new_malformed','missing'] as $key) {
            $this->input(['children'=>[$key=>['position'=>9]]],'PUT');
            $response=$make()->update('p')->getData(true);
            $this->assertFalse($response['status']);
            $this->assertArrayHasKey('children.'.$key.'.label',$response['validation']);
            $this->assertSame(1,NestedChild::count()); $this->assertSame(2,NestedChild::find('old')->position);
        }
    }

    public function testBrowserTemplateEmptyIncrementingChildKeysAreOmittedAfterMiddlewareNormalization(): void
    {
        $make=function () {
            $form=new Form(new NestedIncrementingParent());
            $form->hidden('code');
            $form->hasMany('children',fn($row)=>$row->text('label')->rules('required'));
            $form->morphMany('notes',fn($row)=>$row->text('label')->rules('required'));
            return $form;
        };
        $queries=[];
        DB::listen(function($query)use(&$queries) { if (str_starts_with(strtolower($query->sql),'insert into') && str_contains($query->sql,'nested_notes')) $queries[]=$query->sql; });
        foreach (['POST','PUT'] as $method) {
            if ($method==='PUT') NestedIncrementingParent::create(['code'=>'p']);
            $form=$make();
            foreach (['children','notes'] as $name) {
                $field=$form->fields()->first(fn($field)=>$field->column()===$name);
                $html=(string)$field->render();
                $this->assertStringContainsString('name="'.$name.'[new___LA_KEY__][id]" value=""',$html);
            }
            $this->input(['code'=>'p','children'=>['new_1'=>['id'=>'','label'=>'Browser children']], 'notes'=>['new_2'=>['id'=>'','label'=>'Browser morph']]],$method);
            (new Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull())->handle(request(),fn($request)=>$request);
            $this->assertNull(request()->input('children.new_1.id'));
            $this->assertNull(request()->input('notes.new_2.id'));
            $queries=[];
            $response=$method==='POST'?$form->store():$form->update('p');
            $this->assertTrue($response->getData(true)['status']);
            $parent=NestedIncrementingParent::findOrFail('p');
            $this->assertSame(['Browser children'],$parent->children->pluck('label')->all());
            $this->assertSame(['Browser morph'],$parent->notes->pluck('label')->all());
            $this->assertNull($parent->children->first()->subject_type);
            $this->assertSame(NestedIncrementingParent::class,$parent->notes->first()->subject_type);
            $this->assertSame('p',$parent->children->first()->subject_id);
            $this->assertSame('p',$parent->notes->first()->subject_id);
            $this->assertGreaterThan(0,$parent->children->first()->id);
            $this->assertGreaterThan(0,$parent->notes->first()->id);
            $this->assertCount(2,$queries);
            foreach ($queries as $sql) {
                $this->assertSame(1,preg_match('/insert into [^(]+\(([^)]+)\)/i',$sql,$matches));
                $this->assertDoesNotMatchRegularExpression('/(?:^|,)\s*[`"]?id[`"]?\s*(?:,|$)/',$matches[1]);
            }
            $this->input(['children'=>['new_explicit'=>['id'=>700,'label'=>'Explicit key']]],'PUT');
            $this->assertTrue($make()->update('p')->getData(true)['status']);
            $this->assertSame('Explicit key',NestedNote::findOrFail(700)->label);
            NestedNote::query()->delete(); NestedParent::query()->delete();
        }
    }

    public function testNewCustomChildZeroKeyRemainsExplicit(): void
    {
        $this->write(['code'=>'custom','children'=>['new_zero'=>['child_code'=>'0','label'=>'Zero key']]]);
        $this->assertSame('0',NestedChild::findOrFail('0')->getKey());
        $this->assertSame('custom',NestedChild::findOrFail('0')->parent_code);
        $this->input(['children'=>['0'=>['position'=>2]]],'PUT');
        $this->assertTrue($this->form()->update('custom')->getData(true)['status']);
        $this->assertSame(1,NestedChild::count());
        $this->assertSame('Zero key',NestedChild::findOrFail('0')->label);
    }

}
