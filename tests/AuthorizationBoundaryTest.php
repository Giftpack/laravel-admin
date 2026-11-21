<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Auth\Database\OperationLog;
use Encore\Admin\Security\HandlerContext;
use Illuminate\Http\Request;

require_once __DIR__.'/Fixtures/AuthorizationHandlers.php';

class AuthorizationBoundaryTest extends TestCase
{
    private function identity(): Administrator
    {
        $user = Administrator::create(['username'=>'operator','name'=>'operator','password'=>'unused']);
        $this->actingAs($user, 'admin');
        return $user;
    }
    private function grant($user, $slug, $method = [], $path = ''): Permission
    {
        $grant = Permission::create(['name'=>$slug,'slug'=>$slug,'http_method'=>$method,'http_path'=>$path]);
        $user->permissions()->attach($grant);
        return $grant;
    }
    private function token($kind, $class, $scope = []): string { return HandlerContext::issue($kind, $class, $scope); }
    private function actionData($class, $scope = [], $extra = []): array
    {
        $data = ['_action'=>$class,'_context'=>$this->token('action', $class, $scope)] + $extra;
        if (isset($scope['model'])) $data['_model'] = $scope['model'];
        if (isset($scope['key'])) $data['_key'] = $scope['key'];
        return $data;
    }
    public function testLiveGrantsRevocationAdministratorAndMethodPaths()
    {
        $user = $this->identity();
        $this->assertFalse($user->can('change'));
        $p = $this->grant($user, 'change', ['GET'], '/reports/*');
        $this->assertTrue($user->can('change'));
        $this->assertTrue($p->shouldPassThrough(Request::create('/admin/reports/1','GET')));
        $this->assertFalse($p->shouldPassThrough(Request::create('/admin/reports/1','POST')));
        $user->load('permissions','roles');
        $user->permissions()->detach($p);
        $this->assertFalse($user->can('change'));
        $role = Role::create(['name'=>'editors','slug'=>'editors']);
        $role->permissions()->attach($p); $user->roles()->attach($role);
        $this->assertTrue($user->can('change'));
        $role->permissions()->detach($p);
        $this->assertFalse($user->can('change'));
        $user->roles()->attach(Role::where('slug','administrator')->first());
        $this->assertTrue($user->can('missing'));
        config(['admin.route.prefix'=>'nested/control']);
        $p->http_path = "POST:/reports/*\nGET:/other"; $p->http_method = [];
        $this->assertTrue($p->shouldPassThrough(Request::create('/nested/control/reports/1','POST')));
        $this->assertFalse($p->shouldPassThrough(Request::create('/nested/control/reports/1','GET')));
        $this->assertFalse($p->shouldPassThrough(Request::create('/admin/reports/1','POST')));
    }
    public function testGenericGuestsAndUntrustedConstructorsAreBlocked()
    {
        foreach (['action'=>'POST','form'=>'POST','selectable'=>'GET','renderable'=>'GET'] as $kind=>$method) {
            $this->call($method, 'admin/_handle_'.$kind.'_');
            $this->assertResponseStatus(302);
        }
        $this->identity();
        foreach ([['_action'=>'BoundaryUnrelated'],['_action'=>'BoundaryRow'],['_action'=>[]]] as $data) {
            $this->call('POST','admin/_handle_action_', $data);
            $this->assertResponseStatus(403);
        }
        foreach (['form','selectable','renderable'] as $kind) {
            $key = $kind === 'form' ? '_form_' : $kind;
            $class = ['form'=>'BoundaryForm','selectable'=>'BoundarySelectable','renderable'=>'BoundaryRenderable'][$kind];
            $before = $class::$constructed;
            $this->call($kind === 'form' ? 'POST' : 'GET', 'admin/_handle_'.$kind.'_', [$key=>$class]);
            $this->assertResponseStatus(403);
            $this->assertSame($before, $class::$constructed);
        }
    }
    public function testRowActionBindsClassModelKeyActorAndChecksFreshPermission()
    {
        $user=$this->identity(); $record=Administrator::create(['username'=>'target','name'=>'target','password'=>'unused']);
        $scope=['model'=>'Encore_Admin_Auth_Database_Administrator','key'=>(string)$record->id];
        $data=$this->actionData('BoundaryRow',$scope);
        $payload=json_decode(Illuminate\Support\Facades\Crypt::decryptString($data['_context']),true);
        $payload['expires']=0;$expired=$data;$expired['_context']=Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload));
        $this->call('POST','admin/_handle_action_',$expired); $this->assertResponseStatus(403);
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(403);
        $grant=$this->grant($user,'change');
        foreach ([['_key'=>'1'],['_model'=>'BoundaryRecord'],['_action'=>'BoundaryDefaultAction'],['_context'=>'forged']] as $override) {
            $before=BoundaryRow::$constructed;
            $this->call('POST','admin/_handle_action_',array_replace($data,$override)); $this->assertResponseStatus(403);
            $this->assertSame($before,BoundaryRow::$constructed);
        }
        $this->assertSame('target',$record->fresh()->name);
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(200);
        $this->assertSame('changed',$record->fresh()->name);
        $user->permissions()->detach($grant);
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(403);
        $this->actingAs(Administrator::first(),'admin');
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(403);
    }
    public function testBrowseGrantNeverImpliesMutationAndExplicitAbilityWorks()
    {
        $user=$this->identity(); $this->grant($user,'browse',['GET'],'/*');
        $protected=$this->actionData('BoundaryProtectedAction');
        $this->call('POST','admin/_handle_action_',$protected);$this->assertResponseStatus(403);
        $denied=$this->actionData('BoundaryDeniedResponse');
        $this->call('POST','admin/_handle_action_',$denied); $this->assertResponseStatus(403);
        $exceptionData=$this->actionData('BoundaryExceptionAction',[],['password'=>'fixture-sensitive-value']);
        $this->call('POST','admin/_handle_action_',$exceptionData);$this->assertResponseStatus(200);
        $this->assertFalse(str_contains($this->response->getContent(),'fixture-sensitive-value'), 'Failure response must omit credential values.');
        $this->assertFalse(json_decode($this->response->getContent(),true)['status']);
        $data=$this->actionData('BoundaryDefaultAction');
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(403);
        config(['admin.handler_abilities'=>['BoundaryDefaultAction'=>'change']]);
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(403);
        $this->grant($user,'change');
        $this->call('POST','admin/_handle_action_',$protected);$this->assertResponseStatus(200);
        $this->call('POST','admin/_handle_action_',$data); $this->assertResponseStatus(200);
    }
    public function testMixedBatchRejectsAllRecordsBeforeAnyWrite()
    {
        $user=$this->identity();$this->grant($user,'change');
        $a=BoundaryRecord::create(['username'=>'a','name'=>'target','password'=>'unused']);$b=BoundaryRecord::create(['username'=>'b','name'=>'protected','password'=>'unused']);
        $data=$this->actionData('BoundaryBatch',['model'=>'BoundaryRecord'],['_key'=>[$a->id,$b->id]]);
        $this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(403);
        $this->assertSame('target',$a->fresh()->name);$this->assertSame('protected',$b->fresh()->name);
        $typed=$this->actionData('BoundaryCollectionBatch',['model'=>'BoundaryRecord'],['_key'=>[$a->id,$b->id]]);
        $this->call('POST','admin/_handle_action_',$typed);$this->assertResponseStatus(403);
        $missing=$data;$missing['_key']=[$a->id,999999];$this->call('POST','admin/_handle_action_',$missing);$this->assertResponseStatus(404);
        $typed['_key']=[$a->id,$a->id];$this->call('POST','admin/_handle_action_',$typed);$this->assertResponseStatus(200);
        $data['_key']=[$a->id];$this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(200);
        $this->assertSame('changed',$a->fresh()->name);
    }
    public function testZeroKeysResolveRealSubjectsBeforeAuthorization()
    {
        $this->actingAs(Administrator::first(), 'admin');
        Illuminate\Support\Facades\Schema::create('boundary_key_records', function ($table) { $table->string('code')->primary(); $table->string('name'); });
        $record=BoundaryKeyRecord::create(['code'=>'0','name'=>'target']);
        $row=$this->actionData('BoundaryKeyRow',['model'=>'BoundaryKeyRecord','key'=>'0']);
        $batch=$this->actionData('BoundaryKeyBatch',['model'=>'BoundaryKeyRecord'],['_key'=>'0']);
        $this->call('POST','admin/_handle_action_',$row);$this->assertResponseStatus(200);
        $this->assertSame('changed',$record->fresh()->name);
        foreach (['0',0,['0',0]] as $keys) {
            $record->update(['name'=>'target']);$batch['_key']=$keys;
            $this->call('POST','admin/_handle_action_',$batch);$this->assertResponseStatus(200);
            $this->assertSame('changed',$record->fresh()->name);
        }
        $record->update(['name'=>'protected']);
        $policy=$this->actionData('BoundaryKeyPolicyRow',['model'=>'BoundaryKeyRecord','key'=>'0']);
        $this->call('POST','admin/_handle_action_',$policy);$this->assertResponseStatus(403);
        $before=BoundaryKeyBatch::$handled;$batch['_key']='0';
        $this->call('POST','admin/_handle_action_',$batch);$this->assertResponseStatus(403);
        $this->assertSame($before,BoundaryKeyBatch::$handled);$this->assertSame('protected',$record->fresh()->name);
        $record->delete();
        foreach ([['BoundaryKeyRow',$row],['BoundaryKeyBatch',$batch]] as [$class,$data]) {
            $authorized=$class::$authorized;$handled=$class::$handled;
            $this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(404);
            $this->assertSame($authorized,$class::$authorized);$this->assertSame($handled,$class::$handled);
            foreach([null,'',' ',[],[null],[''],[[]],false,true,1.5] as $invalid) {
                $data['_key']=$invalid;
                $this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(403);
                $this->assertSame($authorized,$class::$authorized);$this->assertSame($handled,$class::$handled);
            }
        }
        foreach (['BoundaryMissingRow','BoundaryMissingBatch'] as $class) {
            $scope=['model'=>'BoundaryKeyRecord'];if($class==='BoundaryMissingRow')$scope['key']='0';
            $data=$this->actionData($class,$scope,['_key'=>'0']);
            $authorized=$class::$authorized;$handled=$class::$handled;
            $this->call('POST','admin/_handle_action_',$data);$this->assertResponseStatus(404);
            $this->assertSame($authorized,$class::$authorized);$this->assertSame($handled,$class::$handled);
        }
    }
    public function testFormSelectableRenderableRequireBoundContextAndExplicitAuthorization()
    {
        $user=$this->identity();
        $fixtures=[['form','_form_','BoundaryForm',[],[]],['selectable','selectable','BoundarySelectable',['args'=>['1']],['args'=>['1']]],['renderable','renderable','BoundaryRenderable',['key'=>'allowed'],['key'=>'allowed']]];
        foreach($fixtures as [$kind,$key,$class,$scope,$extra]) {
            $data=[$key=>$class,'_context'=>$this->token($kind,$class,$scope)]+$extra;
            $this->call($kind==='form'?'POST':'GET','admin/_handle_'.$kind.'_',$data);$this->assertResponseStatus(403);
        }
        $this->grant($user,'change');$this->grant($user,'browse');
        foreach($fixtures as [$kind,$key,$class,$scope,$extra]) {
            $data=[$key=>$class,'_context'=>$this->token($kind,$class,$scope)]+$extra;
            $this->call($kind==='form'?'POST':'GET','admin/_handle_'.$kind.'_',$data);$this->assertResponseStatus(200);
            if ($extra) {
                $data[array_key_first($extra)]=$kind==='selectable'?['2']:'denied';
                $this->call('GET','admin/_handle_'.$kind.'_',$data);$this->assertResponseStatus(403);
            }
        }
    }
    public function testPivotCleanupAndFailureRollbackOnSelectedConnection()
    {
        $user=$this->identity();$p=$this->grant($user,'change');$role=Role::create(['name'=>'x','slug'=>'x']);
        $role->permissions()->attach($p);$user->roles()->attach($role);
        $menu=Encore\Admin\Auth\Database\Menu::first();$role->menus()->attach($menu);
        try { $user->permissions()->sync([999999 => ['invalid_pivot_column'=>'failure']]); $this->fail('Expected sync failure'); }
        catch (Illuminate\Database\QueryException $e) { $this->assertTrue($user->permissions()->whereKey($p->id)->exists()); }
        Role::deleted(function($model){ if($model->slug==='x') throw new RuntimeException('injected deletion failure'); });
        try { $role->delete(); $this->fail('Expected rollback'); } catch (RuntimeException $e) { $this->assertSame('injected deletion failure',$e->getMessage()); }
        $this->assertNotNull($role->fresh());$this->assertTrue($role->permissions()->exists());$this->assertTrue($role->administrators()->exists());$this->assertTrue($role->menus()->exists());
        Role::flushEventListeners();
        $boot = new ReflectionMethod(Role::class, 'boot'); $boot->invoke(null);
        $role->fresh()->delete();
        $this->assertFalse($user->roles()->exists());
        $p->delete();$this->assertFalse($user->permissions()->exists());
        $p=$this->grant($user,'new');$user->delete();
        $this->assertSame(0,Illuminate\Support\Facades\DB::table(config('admin.database.user_permissions_table'))->count());
    }
    public function testOperationLogPersistenceHistoricalRedactionAndPolicies()
    {
        $user=$this->identity();
        config(['admin.operation_log.enable'=>true,'admin.operation_log.sensitive_keys'=>['pin','backupCode']]);
        $secret='fixture-sensitive-value';
        $input=['name'=>'useful','password'=>$secret,'password_confirmation'=>$secret,'nested'=>[['accessToken'=>$secret,'api-key'=>$secret,'pin'=>$secret,'backupCode'=>$secret,'value'=>'context']]];
        $req=Request::create('/admin/audit','POST',$input);
        $middleware=new Encore\Admin\Middleware\LogOperation;
        $this->assertSame('next',$middleware->handle($req,fn()=> 'next'));
        $log=OperationLog::latest('id')->first();
        $this->assertSame($user->id,$log->user_id);
        $this->assertFalse(str_contains($log->getRawOriginal('input'),$secret), 'Stored audit must omit credential values.');
        $this->assertStringContainsString('useful',$log->input);$this->assertStringContainsString('context',$log->input);
        Illuminate\Support\Facades\DB::table($log->getTable())->where('id',$log->id)->update(['input'=>json_encode($input)]);
        $this->assertFalse(str_contains($log->fresh()->input,$secret), 'Historical presentation must omit credential values.');
        $this->assertTrue(str_contains($log->fresh()->getRawOriginal('input'),$secret), 'Historical storage must remain unchanged.'); // No historical rewrite.
        $before=OperationLog::count();
        foreach ([['admin.operation_log.enable'=>false],['admin.operation_log.allowed_methods'=>['get']],['admin.operation_log.except'=>['post:admin/audit']]] as $config) {
            config(['admin.operation_log.enable'=>true,'admin.operation_log.allowed_methods'=>[],'admin.operation_log.except'=>[]]+$config);
            config($config);$middleware->handle($req,fn()=> 'next');$this->assertSame($before,OperationLog::count());
        }
        config(['admin.operation_log.enable'=>true,'admin.operation_log.allowed_methods'=>[],'admin.operation_log.except'=>[],'admin.database.operation_log_table'=>'missing_audit_table']);
        $this->assertSame('next',$middleware->handle($req,fn()=> 'next'));
        config(['admin.operation_log.failure_policy'=>'abort']);
        try{$middleware->handle($req,fn()=> 'next');$this->fail('Expected audit failure');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(503,$e->getStatusCode());$this->assertFalse(str_contains($e->getMessage(),$secret), 'Audit failure must omit credential values.');}
    }
}
