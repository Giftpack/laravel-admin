<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FormLifecycleRecord extends Model
{
    protected $table = 'form_lifecycle_records';
    protected $guarded = [];
    protected $casts = ['collection_data'=>'array'];
    public $timestamps = false;
    public $incrementing = false;
}
class FormLifecycleSoftRecord extends FormLifecycleRecord { use SoftDeletes; }

class FormLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->be(Administrator::first(), 'admin');
        Schema::create('form_lifecycle_records', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->string('locked')->nullable();
            $t->string('ignored')->nullable();
            $t->json('collection_data')->nullable();
            $t->softDeletes();
        });
    }
    private function requestData(array $data, string $method = 'POST', array $server = []): void
    {
        $request = Request::create('/admin/records', $method, $data, [], [], $server);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Illuminate\Support\Facades\Request::swap($request);
    }
    private function form($model = null): Form
    {
        $form = new Form($model ?? new FormLifecycleRecord());
        $form->hidden('id');
        $form->text('name')->rules('required');
        $form->text('locked')->readonly();
        $form->text('ignored');
        $form->ignore('ignored');
        return $form;
    }
    public function testCreateRejectsMissingRequiredAndMalformedScalarBeforeWrites(): void
    {
        foreach ([['id'=>1], ['id'=>1,'name'=>['bad']]] as $input) {
            $this->requestData($input, 'POST', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
            $response = $this->form()->store();
            $this->assertFalse($response->getData(true)['status']);
            $this->assertSame(0, FormLifecycleRecord::count());
        }
    }
    public function testReadonlyIgnoredAndUnknownValuesCannotBeForged(): void
    {
        $record = FormLifecycleRecord::create(['id'=>1,'name'=>'before','locked'=>'original','ignored'=>'original']);
        $this->requestData(['name'=>'after','locked'=>'forged','ignored'=>'forged','deleted_at'=>'2020-01-01'], 'PUT');
        $this->form()->update(1);
        $this->assertSame('after', $record->fresh()->name);
        $this->assertSame('original', $record->fresh()->locked);
        $this->assertSame('original', $record->fresh()->ignored);
        $this->assertNull($record->fresh()->deleted_at);
    }
    public function testBatchDeleteIsAtomicAndZeroIsARealKey(): void
    {
        FormLifecycleRecord::insert([['id'=>0,'name'=>'zero'],['id'=>1,'name'=>'one']]);
        $this->requestData([], 'DELETE');
        $response = $this->form()->destroy('1,404');
        $this->assertFalse($response->getData(true)['status']);
        $this->assertNotNull(FormLifecycleRecord::find(1));
        $this->assertTrue($this->form()->destroy('0')->getData(true)['status']);
        $this->assertNull(FormLifecycleRecord::find(0));
    }
    public function testModelConnectionRollbackOnCreateAndUpdateFailure(): void
    {
        config(['database.connections.forms'=>config('database.connections.'.config('database.default'))]);
        foreach (['POST','PUT'] as $method) {
            $record = (new FormLifecycleRecord())->setConnection('forms');
            if ($method === 'PUT') $record->forceFill(['id'=>8,'name'=>'before'])->save();
            $this->requestData(['id'=>8,'name'=>'after'], $method);
            FormLifecycleRecord::saved(function () { throw new RuntimeException('fixture failure after SQL'); });
            try {
                $form = $this->form((new FormLifecycleRecord())->setConnection('forms'));
                $method === 'POST' ? $form->store() : $form->update(8);
                $this->fail('Expected persistence failure');
            } catch (RuntimeException $e) {
                $this->assertSame('fixture failure after SQL', $e->getMessage());
            } finally { FormLifecycleRecord::flushEventListeners(); }
            $fresh = $record->newQuery()->find(8);
            $this->assertSame($method === 'POST' ? null : 'before', $fresh?->name);
        }
    }
    public function testHooksOrderEarlyResponsesAndSoftHardDelete(): void
    {
        $events = [];
        $this->requestData(['id'=>1,'name'=>'created'], 'POST', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $form = $this->form(new FormLifecycleSoftRecord());
        foreach (['submitted','saving','saved','deleting','deleted'] as $hook) {
            $form->$hook(function (Form $received, $parameters) use (&$events, $hook, $form) {
                $this->assertSame($form, $received);
                $events[] = $hook;
                if ($hook === 'submitted') $this->assertNull($received->name);
                if ($hook === 'saving') $this->assertSame('created', $received->name);
                if ($hook === 'deleting') $this->assertSame('1', $parameters);
            });
        }
        $this->assertTrue($form->store()->getData(true)['status']);
        $this->assertSame(['submitted','saving','saved'], $events);
        $this->assertTrue($form->destroy('1')->getData(true)['status']);
        $this->assertTrue(FormLifecycleSoftRecord::withTrashed()->find(1)->trashed());
        $this->assertSame(['submitted','saving','saved','deleting','deleted'], $events);
        $this->assertTrue($form->destroy('1')->getData(true)['status']);
        $this->assertNull(FormLifecycleSoftRecord::withTrashed()->find(1));
        foreach (['submitted','saving'] as $hook) {
            $form = $this->form(); $form->$hook(fn () => response('stopped', 409));
            $this->assertSame(409, $form->store()->getStatusCode());
            $this->assertSame(0, FormLifecycleRecord::count());
        }
        $form = $this->form(); $form->saved(fn () => response('saved response', 202));
        $this->assertSame(202, $form->store()->getStatusCode());
        $this->assertNotNull(FormLifecycleRecord::find(1));
        $form->deleting(fn () => response('stop delete',409));
        $this->assertSame(409,$form->destroy('1')->getStatusCode());
        $this->assertNotNull(FormLifecycleRecord::find(1));
    }
    public function testCommonValidationResponsesOldInputAndNestedKeys(): void
    {
        foreach ([[],['HTTP_X_PJAX'=>'true','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest'],['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']] as $server) {
            $this->requestData(['name'=>'','payload'=>['code'=>'bad'],'password'=>'private-secret','password_confirmation'=>'private-secret'], 'POST', $server);
            $form = $this->form();
            $form->text('payload.code')->rules('in:valid', ['in'=>'Controlled nested error']);
            $errors = $form->validationMessages(request()->all());
            $this->assertSame('Controlled nested error', $errors->first('payload.code'));
            $response = $form->store();
            if (request()->ajax() && !request()->pjax()) {
                $this->assertSame(200,$response->getStatusCode());
                $this->assertFalse($response->getData(true)['status']);
                $this->assertArrayHasKey('payload.code',$response->getData(true)['validation']);
            } else {
                $this->assertSame(302,$response->getStatusCode());
                $this->assertSame('bad',session()->getOldInput('payload.code'));
                $this->assertTrue(session('errors')->has('payload.code'));
                $this->assertNull(session()->getOldInput('password'));
                $this->assertNull(session()->getOldInput('password_confirmation'));
            }
            $this->assertSame(0,FormLifecycleRecord::count());
        }
        FormLifecycleRecord::create(['id'=>1,'name'=>'before']);
        $this->requestData(['name'=>''], 'PUT', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $this->assertFalse($this->form()->update(1)->getData(true)['status']);
        $this->requestData(['name'=>'','_edit_inline'=>true], 'PUT', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $this->assertSame(422,$this->form()->update(1)->getStatusCode());
        $this->assertSame('before',FormLifecycleRecord::find(1)->name);
    }
    public function testNullDefaultsAndExplicitEmptyUpdateKeepTheirMeaning(): void
    {
        $this->requestData(['id'=>1,'name'=>null], 'POST', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $form=new Form(new FormLifecycleRecord());
        $form->hidden('id');
        $field=$form->text('name')->default('UI default')->rules('nullable');
        $this->assertSame('UI default',$field->value());
        $this->assertTrue($form->store()->getData(true)['status']);
        $this->assertNull(FormLifecycleRecord::find(1)->name);
        $this->requestData(['name'=>'forged fallback'], 'PUT', ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $form = new Form(new FormLifecycleRecord()); $form->text('name')->rules('nullable');
        $form->update(1, []);
        $this->assertNull(FormLifecycleRecord::find(1)->name);
        $form = new Form(new FormLifecycleRecord()); $form->text('name')->rules('nullable');
        $form->update(1, ['name'=>'0']);
        $this->assertSame('0',FormLifecycleRecord::find(1)->name);
        $form = new Form(new FormLifecycleRecord()); $form->text('name')->rules('nullable');
        $form->update(1, ['name'=>null]);
        $this->assertNull(FormLifecycleRecord::find(1)->name);
        foreach (['',',','1,,2',[],false] as $malformed) {
            $this->assertFalse($form->destroy($malformed)->getData(true)['status']);
            $this->assertNotNull(FormLifecycleRecord::find(1));
        }
    }

    public function testRequiredCollectionsRejectMissingAndEmptyBeforePersistence(): void
    {
        foreach (['keyValue','list','tags'] as $type) {
            $empty = match ($type) { 'keyValue'=>['keys'=>[], 'values'=>[]], 'list'=>['values'=>[]], default=>[''] };
            $zero = match ($type) { 'keyValue'=>['keys'=>['zero'], 'values'=>['0']], 'list'=>['values'=>['0']], default=>['0',''] };
            foreach ([[], ['collection_data'=>$empty]] as $input) {
                $this->requestData(['id'=>50]+$input,'POST',['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
                $form=new Form(new FormLifecycleRecord()); $form->hidden('id');
                $form->$type('collection_data','Collection')->rules('required');
                $response=$form->store();
                $this->assertFalse($response->getData(true)['status'],$type.' missing/empty rejected');
                $this->assertSame(0,FormLifecycleRecord::count(),$type.' no partial write');
                view()->share('errors',(new Illuminate\Support\ViewErrorBag())->put('default',new Illuminate\Support\MessageBag($response->getData(true)['validation'])));
                $this->assertStringContainsString('has-error',(string)$form->fields()->last()->render(),$type.' collection feedback');
            }
            $this->requestData(['id'=>51,'collection_data'=>$zero],'POST',['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
            $form=new Form(new FormLifecycleRecord()); $form->hidden('id'); $form->$type('collection_data','Collection')->rules('required');
            $this->assertFalse($form->validationMessages(request()->all()),$type.' zero valid');
            $this->assertTrue($form->store()->getData(true)['status'],$type.' zero persisted');
            $this->assertSame(match ($type) { 'keyValue'=>['zero'=>'0'], 'list'=>['0'], default=>'0' },FormLifecycleRecord::find(51)->collection_data);
            FormLifecycleRecord::find(51)->delete();
            $record=FormLifecycleRecord::create(['id'=>50,'collection_data'=>'unchanged']);
            $this->requestData([], 'PUT',['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
            $form=new Form(new FormLifecycleRecord()); $form->$type('collection_data','Collection')->rules('required');
            $this->assertTrue($form->update(50)->getData(true)['status'],$type.' absent partial update');
            $this->assertSame('unchanged',$record->fresh()->collection_data);
            $this->requestData(['collection_data'=>$empty],'PUT',['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
            $form=new Form(new FormLifecycleRecord()); $form->$type('collection_data','Collection')->rules('required');
            $this->assertFalse($form->update(50)->getData(true)['status'],$type.' empty update rejected');
            $this->assertSame('unchanged',$record->fresh()->collection_data);
            $record->delete();
            $this->requestData([], 'POST');
            $optional=new Form(new FormLifecycleRecord()); $optional->$type('collection_data')->rules('min:2');
            $this->assertFalse($optional->validationMessages([]),$type.' optional missing create');
        }
    }
    public function testMalformedOldInputSurvivesNormalAndPjaxRedirectRendering(): void
    {
        $router=app('router');
        $make=function () {
            $form=new Form(new FormLifecycleRecord());
            $form->hidden('id');
            $form->text('name','Name');
            $form->checkbox('choices','Choices')->options(['0'=>'Zero']);
            $form->text('locked','Sibling');
            $form->password('password'); $form->password('password_confirmation');
            return $form;
        };
        $router->middleware('web')->get('/form-cycle1',function () use ($make) {
            $html=''; foreach ($make()->fields() as $field) $html.=(string)$field->render();
            return $html;
        });
        $router->middleware('web')->post('/form-cycle1',fn ()=>$make()->store());
        foreach ([[],['HTTP_X_PJAX'=>'true','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']] as $server) {
            foreach ([['name'=>['bad'],'choices'=>['0']],['name'=>'Valid name','choices'=>'bad']] as $bad) {
                $input=$bad+['id'=>99,'locked'=>'Valid sibling','password'=>'private-secret','password_confirmation'=>'private-secret'];
                $this->call('POST','/form-cycle1',$input,[],[],['HTTP_REFERER'=>'http://localhost/form-cycle1']+$server);
                $this->assertResponseStatus(302);
                $this->assertSame(0,FormLifecycleRecord::count());
                $errorKey=is_array($bad['name']) ? 'name' : 'choices';
                $this->assertTrue(session('errors')->has($errorKey));
                $this->assertSame('Valid sibling',session()->getOldInput('locked'));
                $this->assertNull(session()->getOldInput($errorKey));
                $this->assertNull(session()->getOldInput('password'));
                $this->assertNull(session()->getOldInput('password_confirmation'));
                $this->call('GET','/form-cycle1',[],[],[],$server);
                $this->assertResponseStatus(200);
                $this->assertStringContainsString('Valid sibling',$this->response->getContent());
                $this->assertStringContainsString('has-error',$this->response->getContent());
                $this->assertStringNotContainsString('private-secret',$this->response->getContent());
            }
        }
    }

}
