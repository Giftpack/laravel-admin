<?php

use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporter;
use Encore\Admin\Grid\Exporters\CsvExporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ExportRecord;

require_once __DIR__.'/Fixtures/ExportModels.php';

class GridExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('export_records', function (Blueprint $table) {
            $table->increments('id'); $table->integer('tenant'); $table->string('title');
            $table->integer('rank'); $table->softDeletes();
        });
        foreach (range(1, 205) as $i) ExportRecord::create(['tenant'=>7, 'title'=>'keep '.$i, 'rank'=>$i]);
        ExportRecord::create(['tenant'=>8,'title'=>'foreign','rank'=>999]);
        ExportRecord::create(['tenant'=>7,'title'=>'deleted','rank'=>998])->delete();
    }

    private function grid(array $input = []): Grid
    {
        $request = Request::create('/admin/export-fixture','GET',$input);
        $this->app->instance('request',$request); Illuminate\Support\Facades\Request::swap($request);
        $grid = new Grid(new ExportRecord());
        $grid->column('id')->sortable(); $grid->column('title'); $grid->column('rank')->sortable();
        $grid->model()->where('rank','<=',204);
        $grid->filter(function ($filter) { $filter->disableIdFilter(); $filter->between('rank'); $filter->scope('small')->where('rank','<',10); });
        $grid->quickSearch('title');
        return $grid;
    }

    public function testAllAndSelectedRetainFiltersOrderingAndTenantSoftDeleteConstraints()
    {
        $input=['rank'=>['start'=>3,'end'=>103],'_sort'=>['column'=>'rank','type'=>'desc']];
        foreach (['all','selected:1,3,100,206,207'] as $scope) {
            $exporter=(new Exporter($this->grid($input)))->resolve(null)->withScope($scope);
            $chunks=[]; $ids=[];
            $this->assertTrue($exporter->chunk(function ($rows) use (&$chunks,&$ids) { $chunks[]=$rows->count(); array_push($ids,...$rows->modelKeys()); },100));
            $this->assertSame($scope==='all'?[100,1]:[2],$chunks);
            $this->assertSame($scope==='all'?range(103,3):[100,3],$ids);
            $this->assertSame($ids,array_column($exporter->getData(),'id'));
        }
        $scope=(new Exporter($this->grid(['_scope_'=>'small'])))->resolve(null)->withScope('all');
        $this->assertSame(range(1,9),array_column($scope->getData(),'id'));
    }

    public function testExplicitCurrentPageUsesFilteredSortedResultsAndValidatedSize()
    {
        foreach ([['per_page'=>10],['per_page'=>['bad']],['per_page'=>0],['per_page'=>999999]] as $input) {
            $grid=$this->grid($input+['page'=>1,'_sort'=>['column'=>'rank','type'=>'desc']]); $grid->paginate(10);
            $exporter=(new Exporter($grid))->resolve(null)->withScope('page:2');
            $this->assertSame(range(194,185),array_column($exporter->getData(),'id'));
            $this->assertSame(range(194,185),array_column($exporter->getData(),'id'));
        }
        $exporter=(new Exporter($this->grid(['__search__'=>'keep 201'])))->resolve(null)->withScope('all');
        $this->assertSame([201],array_column($exporter->getData(),'id'));
    }

    public function testRelationExportDoesNotBroadenOrMutateTheOriginalRelation()
    {
        $relation=ExportRecord::findOrFail(1)->peers();
        $grid=$this->grid();$grid->model()->setRelation($relation);
        $exporter=(new Exporter($grid))->resolve(null)->withScope('all');
        $ids=[];$this->assertTrue($exporter->chunk(function ($rows) use (&$ids) { array_push($ids,...$rows->modelKeys()); },2));
        $this->assertSame([1,2,3],$ids);
        $this->assertSame([1,2,3],$relation->orderBy('id')->get()->modelKeys());
        $grid=$this->grid();$grid->paginate(1);$grid->model()->setRelation($relation);
        $page=(new Exporter($grid))->resolve(null)->withScope('page:2');
        $this->assertSame([2],array_column($page->getData(),'id'));
        $this->assertSame([1,2,3],$relation->get()->modelKeys());
    }

    public function testCollectionCallbacksRetainGetDataAndCurrentPageContracts()
    {
        $grid=$this->grid(); $calls=0;
        $grid->paginate(10);
        $grid->model()->collection(function ($rows) use (&$calls) {
            ++$calls;
            return $rows->each(fn ($row) => $row->setAttribute('title','collected:'.$row->id));
        });
        $exporter=(new Exporter($grid))->resolve(null)->withScope('page:2');
        $titles=[];
        $this->assertTrue($exporter->chunk(function ($rows) use (&$titles) { array_push($titles,...$rows->pluck('title')); },3));
        $this->assertSame(array_map(fn ($id)=>'collected:'.$id,range(11,20)),$titles);
        $this->assertSame(1,$calls);
        $this->assertSame('collected:11',$exporter->getData()[0]['title']);
        $this->assertFalse($exporter->chunk(fn () => false,3));
    }

    public function testInvalidScopeNeverFallsBackToAll()
    {
        foreach (['bogus','page','page:0','page:-1','page:abc','selected:','selected:1,', 'page:'.PHP_INT_MAX, ['all']] as $scope) {
            try { (new Exporter($this->grid()))->resolve(null)->withScope($scope)->getQuery(); $this->fail('Malformed scope accepted'); }
            catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(422,$exception->getStatusCode()); }
        }
    }

    public function testDisabledExportIsDeniedAndDriverCallbackBelongsToItsGrid()
    {
        $first=$this->grid(['_export_'=>'all']); $second=$this->grid(['_export_'=>'all']);
        $resolve=fn ($grid) => (function () { return $this->getExporter('all'); })->call($grid);
        $a=$resolve($first); $this->assertSame($a,$resolve($first)); $this->assertNotSame($a,$resolve($second));
        $first->disableExport();
        try { $resolve($first); $this->fail('Disabled export accepted'); }
        catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403,$exception->getStatusCode()); }
    }
}
