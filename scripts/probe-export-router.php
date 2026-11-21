<?php
// Internal router for probe-exports.php; every request is a separate native send/exit lifecycle.
require getenv('EXPORT_AUTOLOAD');
require_once '/application/tests/Support/PackageTestHost.php';
require_once '/application/tests/Fixtures/ExportModels.php';
PackageTestHost::assertDisposableEnvironment();
$app=PackageTestHost::create(PackageTestHost::directory());
config(['admin.check_route_permission'=>true,'admin.operation_log.enable'=>false,'app.debug'=>false]);
config(['logging.default'=>'stderr','logging.channels.stderr'=>['driver'=>'monolog','handler'=>Monolog\Handler\StreamHandler::class,'with'=>['stream'=>'php://stderr']]]);
$case=$_GET['case']??'all';
$metrics=getenv('EXPORT_PROBE_DIR').'/'.$case.'.json';
$rows=0; $chunks=[]; $base=memory_get_usage(true);
register_shutdown_function(function () use ($metrics,&$rows,&$chunks,$base) {
    file_put_contents($metrics,json_encode(['rows'=>$rows,'chunks'=>$chunks,'peak_bytes'=>memory_get_peak_usage(true),'peak_rss_bytes'=>(getrusage()['ru_maxrss']??0)*1024,'base_bytes'=>$base,'last_error'=>error_get_last()]));
});
$app['auth']->guard('admin')->setUser(Encore\Admin\Auth\Database\Administrator::findOrFail($case==='denied'?2:1));
if (class_exists(Maatwebsite\Excel\Excel::class)) {
    class FixtureExcelExporter extends Encore\Admin\Grid\Exporters\ExcelExporter {
        protected ?string $fileName='fixture.xlsx';
        protected $columns=['id'=>'ID','title'=>'Title','rank'=>'Rank'];
    }
}
class MeasuredCsvExporter extends Encore\Admin\Grid\Exporters\CsvExporter {
    public function chunk(callable $callback,$count=100) {
        return parent::chunk(function ($collection) use ($callback) { $GLOBALS['chunks'][]=$collection->count(); return $callback($collection); },$count);
    }
}
$app['router']->middleware(['web',Encore\Admin\Middleware\Authenticate::class,Encore\Admin\Middleware\Permission::class])->get('/admin/export-fixture',function () use ($case,&$rows) {
    $grid=new Encore\Admin\Grid(new Tests\Fixtures\ExportRecord());
    $grid->column('id','ID')->sortable();
    $grid->column('title','Title')->display(fn ($v) => 'display:'.$v);
    $grid->column('rank','Rank')->sortable();
    $grid->column('tenant','Tenant')->hide();
    $grid->model()->where('rank','<=',30000);
    $grid->paginate(10);
    $grid->filter(function ($filter) { $filter->disableIdFilter();$filter->between('rank'); });
    $grid->quickSearch('title');
    if ($case==='disabled') $grid->disableExport();
    if ($case==='allhidden') $grid->hideColumns(['id','title','rank','tenant']);
    if (str_starts_with($case,'excel')) {
        $grid->exporter(new FixtureExcelExporter());
    } else {
        $grid->exporter(new MeasuredCsvExporter());
        $grid->export(function ($exporter) use ($case,&$rows) {
            $exporter->filename('fixture')->originalValue(['title']);
            if ($case==='fidelity') {
                $exporter->originalValue([])->only(['id','title','rank','tenant'])->except(['rank'])->title('title',fn ($title)=>$title.',標題')
                    ->column('title',fn ($value,$original) => $original.'|'.$value);
            } else {
                $exporter->column('rank',function ($value) use ($case,&$rows) {
                    ++$rows;
                    if ($case==='failure' && $rows===101) throw new RuntimeException('Injected export failure');
                    return $value;
                });
            }
        });
    }
    return $grid->render();
});
$request=Illuminate\Http\Request::capture();
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
