<?php
require '/application/vendor/autoload.php';
require_once '/application/tests/Support/PackageTestHost.php';
PackageTestHost::assertDisposableEnvironment();
$app=PackageTestHost::create(PackageTestHost::directory());
$app['auth']->guard('admin')->setUser(Encore\Admin\Auth\Database\Administrator::first());
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);Illuminate\Support\Facades\Request::swap($request);
Illuminate\Support\Facades\DB::enableQueryLog();$start=hrtime(true);memory_reset_peak_usage();
register_shutdown_function(function()use($start){file_put_contents(getenv('BENCHMARK_METRICS'),json_encode(['milliseconds'=>round((hrtime(true)-$start)/1e6,3),'queries'=>count(Illuminate\Support\Facades\DB::getQueryLog()),'peak_bytes'=>memory_get_peak_usage(true),'rss_bytes'=>(getrusage()['ru_maxrss']??0)*1024]));});
// The original User fixture appends a profile accessor that assumes profiles exist; export rows1001..10000 intentionally have none.
class BenchmarkExportUser extends Tests\Models\User {protected $appends=[];}
$grid=new Encore\Admin\Grid(new BenchmarkExportUser());$grid->model()->orderBy('id');$grid->column('id');$grid->column('username');$grid->column('email');$grid->export(fn($exporter)=>$exporter->originalValue(['id','username','email']));$grid->render();
http_response_code(500);echo 'Exporter did not send/exit';
