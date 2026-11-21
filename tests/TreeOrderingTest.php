<?php

use Encore\Admin\Tree;
use Encore\Admin\Auth\Database\Menu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

require_once __DIR__.'/Fixtures/TreeModels.php';

class TreeOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tree_records', function (Blueprint $t) {
            $t->string('code')->primary(); $t->string('ancestor')->default('0'); $t->string('label');
            $t->integer('position')->default(0); $t->softDeletes();
        });
    }
    private function records(): void
    {
        TreeRecord::insert([
            ['code'=>'a','ancestor'=>'0','label'=>'Alpha','position'=>2],
            ['code'=>'b','ancestor'=>'0','label'=>'Beta','position'=>1],
            ['code'=>'c','ancestor'=>'a','label'=>'Child','position'=>3],
            ['code'=>'d','ancestor'=>'c','label'=>'Deep','position'=>4],
            ['code'=>'z','ancestor'=>'0','label'=>'Zero','position'=>0],
        ]);
    }
    private function request(array $input): void
    {
        $r=Request::create('/admin/tree','POST',$input); $this->app->instance('request',$r); Illuminate\Support\Facades\Request::swap($r);
    }
    public function testOriginalBootAndSavingLifecycleIsNotRecursive(): void
    {
        TreeRecord::clearBootedModels(); TreeRecord::$boots=0;
        $this->records();
        $this->request(['_order'=>json_encode([['id'=>'a'],['id'=>'b'],['id'=>'z']])]);
        $model=new TreeRecord(['code'=>'new','label'=>'Not inserted']);
        $this->assertFalse($model->save());
        $this->assertSame(1,TreeRecord::$boots);
        $this->assertFalse(TreeRecord::whereKey('new')->exists());
        $this->assertSame(1,TreeRecord::findOrFail('a')->position);
        $this->assertFalse(request()->has('_order'));
        $this->request([]); $this->assertTrue($model->save());
        $this->assertSame(1,TreeRecord::$boots);
    }
    public function testRepresentationAndConfiguredKeysAndTools(): void
    {
        $tree=new Tree(new TreeRecord());
        $this->assertSame([], $tree->getItems());
        $this->assertSame([0=>'ROOT'],TreeRecord::selectOptions());
        $this->records();
        $items=$tree->getItems();
        $this->assertSame(['b','a','z'],array_column($items,'code'));
        $this->assertSame('c',$items[1]['children'][0]['code']);
        $this->assertSame('d',$items[1]['children'][0]['children'][0]['code']);
        $this->assertSame('a',TreeRecord::findOrFail('c')->parent->code);
        $this->assertSame(['c'],TreeRecord::findOrFail('a')->children->modelKeys());
        $options=TreeRecord::selectOptions(null,'Top');
        $this->assertSame([0,'b','a','c','d','z'],array_keys($options));
        $this->assertSame('Top',$options[0]);
        $this->assertSame('┝&nbsp;&nbsp;Alpha',$options['a']);
        $tree->tools(fn($tools)=>$tools->add('First')->add(new Illuminate\Support\HtmlString('Second')));
        $html=$tree->render();
        $this->assertStringContainsString('a - Alpha',$html);
        $this->assertStringContainsString('data-id="d"',$html);
        $this->assertStringContainsString('First Second',$html);
        $this->assertSame(['b'],array_column((new Tree(new TreeRecord()))->query(fn(Illuminate\Database\Eloquent\Model $q)=>$q->where('code','b'))->getItems(),'code'));
    }
    public function testReordersAreStableAndAtomicAndRejectMalformedReferences(): void
    {
        $this->records();
        $tree=new Tree(new TreeRecord());
        $order=[['id'=>'b','children'=>[['id'=>'a','children'=>[['id'=>'c']]]]],['id'=>'d'],['id'=>'z']];
        $tree->saveOrder(json_encode($order));
        $this->assertSame('b',TreeRecord::findOrFail('a')->ancestor);
        $this->assertSame('a',TreeRecord::findOrFail('c')->ancestor);
        $this->assertSame(3,TreeRecord::findOrFail('c')->position);
        $tree->saveOrder(json_encode(array_reverse($order)));
        $this->assertSame(1,TreeRecord::findOrFail('z')->position);
        $before=TreeRecord::orderBy('code')->get()->toArray();
        foreach (['{}','[{"id":"a","children":{}}]', '{broken'] as $badJson) {
            try { $tree->saveOrder($badJson); $this->fail('Non-list JSON accepted'); }
            catch (InvalidArgumentException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
        }
        foreach ([null,42, ['id'=>'a'], [['id'=>null]], [['id'=>true]], [['id'=>[]]], [['id'=>'missing']], [['id'=>'a'],['id'=>'missing']], [['id'=>'a','children'=>[['id'=>'a']]]], [['id'=>'a'],['id'=>'a']], [['id'=>'a','children'=>'bad']], [['id'=>'a','unexpected'=>1]]] as $bad) {
            try { $tree->saveOrder(json_encode($bad)); $this->fail('Malformed reorder accepted: '.json_encode($bad)); }
            catch (InvalidArgumentException $e) { $this->assertNotSame('',$e->getMessage()); }
            $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray());
        }
        try { (new Tree(new TreeRecord()))->query(fn($q)=>$q->where('code','a'))->saveOrder('[{"id":"b"}]'); $this->fail('Foreign record accepted'); }
        catch (InvalidArgumentException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
    }
    public function testSavingCancellationRollsBackAllReorderWrites(): void
    {
        $this->records(); $before=TreeRecord::orderBy('code')->get()->toArray();
        TreeRecord::saving(fn($row)=>$row->code==='c'?false:null);
        try { TreeRecord::saveOrder([['id'=>'b'],['id'=>'c']]); $this->fail('Cancellation ignored'); }
        catch (RuntimeException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
        finally { TreeRecord::flushEventListeners(); TreeRecord::clearBootedModels(); }
    }
    public function testDeletesOnlyImmediateChildrenAndSupportsSoftDeletion(): void
    {
        $this->records(); TreeRecord::findOrFail('a')->delete();
        $this->assertNull(TreeRecord::find('a')); $this->assertNull(TreeRecord::find('c'));
        $this->assertNotNull(TreeRecord::find('d'));
        TreeRecord::query()->delete(); $this->records();
        SoftTreeRecord::findOrFail('a')->delete();
        $this->assertNull(SoftTreeRecord::find('a')); $this->assertNull(SoftTreeRecord::find('c'));
        $this->assertNotNull(SoftTreeRecord::find('d'));
        $this->assertNotNull(SoftTreeRecord::withTrashed()->find('c')->deleted_at);
        SoftTreeRecord::withTrashed()->find('a')->forceDelete();
        $this->assertNull(SoftTreeRecord::withTrashed()->find('a'));
        $this->assertNotNull(SoftTreeRecord::withTrashed()->find('c'));
    }
    public function testSelectedConnectionDoesNotTouchTheDefault(): void
    {
        $this->records();
        $engine=config('database.default');
        config(['database.connections.tree_selected'=>config('database.connections.'.$engine)]);
        $model=(new TreeRecord())->setConnection('tree_selected');
        $queries=[]; DB::listen(function($event)use(&$queries){$queries[]=$event->connectionName;});
        $this->assertCount(3,$model->toTree());
        (new Tree($model))->saveOrder('[{"id":"a"},{"id":"b"}]');
        $this->assertTrue($model->findOrFail('a')->delete());
        $this->assertNull($model->find('c'));
        $this->assertNotNull($model->find('d'));
        $this->assertNotEmpty($queries);
        $this->assertSame(['tree_selected'],array_values(array_unique($queries)));
        $this->assertSame(2,$model->findOrFail('b')->position);
    }
    public function testMenuSiblingOrderLabelsAndDirectChildRoleCleanup(): void
    {
        Menu::query()->delete();
        $role=Encore\Admin\Auth\Database\Role::firstOrFail();
        $root=Menu::create(['title'=>'Parent','icon'=>'fa-bars','order'=>0]);
        $child=Menu::create(['title'=>'Child','icon'=>'fa-bars','parent_id'=>$root->id,'order'=>2]);
        $deep=Menu::create(['title'=>'Deep','icon'=>'fa-bars','parent_id'=>$child->id,'order'=>3]);
        $first=Menu::create(['title'=>'First','icon'=>'fa-bars','order'=>1]);
        foreach ([$root,$child,$deep] as $node) $node->roles()->attach($role);
        $this->assertSame([$root->id,$first->id],array_column($root->toTree(),'id'));
        $this->assertSame('┝&nbsp;&nbsp;Parent',Menu::selectOptions()[$root->id]);
        $this->assertSame([$first->id],array_column((new Tree(new Menu()))->query(fn($q)=>$q->whereKey($first->id))->getItems(),'id'));
        $root->delete();
        $this->assertNull(Menu::find($root->id)); $this->assertNull(Menu::find($child->id));
        $this->assertNotNull(Menu::find($deep->id));
        $pivots=DB::table(config('admin.database.role_menu_table'));
        $this->assertFalse((clone $pivots)->whereIn('menu_id',[$root->id,$child->id])->exists());
        $this->assertTrue((clone $pivots)->where('menu_id',$deep->id)->exists());
    }
    public function testSelfParentAndRetainedAncestorCycleAndDeletionCancellation(): void
    {
        $this->records(); $before=TreeRecord::orderBy('code')->get()->toArray();
        try { TreeRecord::saveOrder([['id'=>'a']], 'd'); $this->fail('Ancestor cycle accepted'); }
        catch (InvalidArgumentException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
        try { (new TreeRecord())->withQuery(fn($q)=>$q->whereIn('code',['a','d']))->saveTreeOrder([['id'=>'a']], 'd'); $this->fail('Hidden ancestor cycle accepted'); }
        catch (InvalidArgumentException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
        try { TreeRecord::saveOrder([['id'=>'b']], 'missing'); $this->fail('Missing parent accepted'); }
        catch (InvalidArgumentException $e) { $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray()); }
        $model=TreeRecord::findOrFail('a'); $model->ancestor='a';
        try { $model->save(); $this->fail('Self-parent accepted'); }
        catch (Exception $e) { $this->assertSame('0',TreeRecord::findOrFail('a')->ancestor); }
        TreeRecord::deleting(fn()=>false);
        try {
            $this->assertFalse(TreeRecord::findOrFail('a')->delete());
            $this->assertSame($before,TreeRecord::orderBy('code')->get()->toArray());
        } finally { TreeRecord::flushEventListeners(); TreeRecord::clearBootedModels(); }
    }
    public function testExplicitConnectionWorksWhenTheDefaultCannotBeResolved(): void
    {
        $this->records(); $engine=config('database.default');
        config(['database.connections.tree_selected'=>config('database.connections.'.$engine)]);
        $model=(new TreeRecord())->setConnection('tree_selected');
        config(['database.default'=>'unconfigured_default','admin.database.connection'=>'tree_selected']);
        try {
            $this->assertCount(3,$model->toTree());
            (new Tree($model))->saveOrder('[{"id":"a"},{"id":"b"}]');
            $this->assertSame(1,$model->findOrFail('a')->position);
            $menu=new Menu(); $this->assertNotEmpty($menu->allNodes());
            $this->assertSame('tree_selected',$menu->getConnectionName());
        } finally { config(['database.default'=>$engine,'admin.database.connection'=>null]); }
    }
    public function testCancelledMenuDeletionRollsBackEventPivotsOnTheSelectedConnection(): void
    {
        $engine=config('database.default');
        config(['database.connections.tree_selected'=>config('database.connections.'.$engine),'admin.database.connection'=>'tree_selected']);
        $parent=Menu::create(['title'=>'Cancellation parent','icon'=>'fa-bars','order'=>1]);
        $child=Menu::create(['title'=>'Cancellation child','icon'=>'fa-bars','parent_id'=>$parent->id,'order'=>2]);
        $role=Encore\Admin\Auth\Database\Role::firstOrFail();
        $parent->roles()->attach($role); $child->roles()->attach($role);
        $connection=$parent->getConnection();
        $nodes=$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $pivots=$connection->table(config('admin.database.role_menu_table'))->orderBy('menu_id')->orderBy('role_id')->get()->map(fn($row)=>(array)$row)->all();
        $queries=[]; DB::listen(function($event)use(&$queries){$queries[]=$event->connectionName;});
        $cancel=true;
        Menu::deleting(function()use(&$cancel){return $cancel?false:null;});
        try {
            $this->assertFalse($parent->delete());
            $this->assertTrue($parent->exists);
            $this->assertSame($nodes,$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
            $this->assertSame($pivots,$connection->table(config('admin.database.role_menu_table'))->orderBy('menu_id')->orderBy('role_id')->get()->map(fn($row)=>(array)$row)->all());
            $this->assertSame(0,$connection->transactionLevel());
            $connection->beginTransaction();
            try {
                $connection->table($parent->getTable())->where('id',$child->id)->update(['title'=>'Outer transaction retained']);
                $outerNodes=$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
                $this->assertFalse($parent->delete());
                $this->assertTrue($parent->exists);
                $this->assertSame(1,$connection->transactionLevel());
                $this->assertSame($outerNodes,$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
                $this->assertSame($pivots,$connection->table(config('admin.database.role_menu_table'))->orderBy('menu_id')->orderBy('role_id')->get()->map(fn($row)=>(array)$row)->all());
            } finally { $connection->rollBack(); }
            $cancel=false;
            $this->assertTrue($parent->delete());
            $this->assertNull(Menu::find($parent->id)); $this->assertNull(Menu::find($child->id));
            $this->assertFalse($connection->table(config('admin.database.role_menu_table'))->whereIn('menu_id',[$parent->id,$child->id])->exists());
            $this->assertSame(['tree_selected'],array_values(array_unique($queries)));
        } finally {
            Menu::flushEventListeners(); Menu::clearBootedModels();
            config(['admin.database.connection'=>null]);
        }
    }
    public function testFailedMenuDeletionRestoresTheSameModelForSaveAndDeleteRetry(): void
    {
        $engine=config('database.default');
        config(['database.connections.tree_selected'=>config('database.connections.'.$engine),'admin.database.connection'=>'tree_selected']);
        $connection=DB::connection('tree_selected');
        $failure=null;
        Menu::deleted(function()use(&$failure){if ($failure) throw $failure;});
        try {
            foreach ([new RuntimeException('Late deleted listener failed'),new Error('Late deleted listener error')] as $exception) {
                $parent=Menu::create(['title'=>'Retry parent','icon'=>'fa-bars','order'=>1]);
                $child=Menu::create(['title'=>'Retry child','icon'=>'fa-bars','parent_id'=>$parent->id,'order'=>2]);
                $role=Encore\Admin\Auth\Database\Role::firstOrFail();
                $parent->roles()->attach($role); $child->roles()->attach($role);
                if ($exception instanceof Error) $parent=$parent->fresh();
                $wasRecentlyCreated=$parent->wasRecentlyCreated;
                $rowCount=Menu::count(); $key=$parent->getKey();
                $pivots=$connection->table(config('admin.database.role_menu_table'))->orderBy('menu_id')->orderBy('role_id')->get()->map(fn($row)=>(array)$row)->all();
                $connection->beginTransaction();
                try {
                    $connection->table($parent->getTable())->where('id',$child->id)->update(['title'=>'Outer work retained']);
                    $nodes=$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
                    $failure=$exception;
                    try { $parent->delete(); $this->fail('Expected late deletion failure'); }
                    catch (Throwable $caught) { $this->assertSame($exception,$caught); }
                    $failure=null;
                    $this->assertSame($nodes,$connection->table($parent->getTable())->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
                    $this->assertSame($pivots,$connection->table(config('admin.database.role_menu_table'))->orderBy('menu_id')->orderBy('role_id')->get()->map(fn($row)=>(array)$row)->all());
                    $this->assertSame(1,$connection->transactionLevel());
                    $this->assertTrue($parent->exists);
                    $this->assertSame($wasRecentlyCreated,$parent->wasRecentlyCreated);
                    $parent->title='Updated after rollback';
                    $this->assertTrue($parent->save());
                    $this->assertSame($key,$parent->getKey());
                    $this->assertSame($rowCount,Menu::count());
                    $this->assertSame('Updated after rollback',Menu::findOrFail($key)->title);
                    $this->assertTrue($parent->delete());
                    $this->assertFalse($parent->exists);
                    $this->assertNull(Menu::find($key)); $this->assertNull(Menu::find($child->id));
                    $this->assertFalse($connection->table(config('admin.database.role_menu_table'))->whereIn('menu_id',[$key,$child->id])->exists());
                    $this->assertNull($parent->delete());
                    $this->assertFalse($parent->exists);
                } finally { $failure=null; $connection->rollBack(); }
            }
            $unsaved=new Menu(['title'=>'Unsaved','icon'=>'fa-bars']);
            $count=Menu::count();
            $this->assertNull($unsaved->delete());
            $this->assertFalse($unsaved->exists);
            $this->assertFalse($unsaved->wasRecentlyCreated);
            $this->assertSame($count,Menu::count());
        } finally {
            Menu::flushEventListeners(); Menu::clearBootedModels();
            config(['admin.database.connection'=>null]);
        }
    }
}
