<?php

require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/tests/TestCase.php';

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporters\CsvExporter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Models\User;

final class BaselineBenchmarkHost extends TestCase
{
    public function benchmark(): void {}
    public function start(): void { parent::setUp(); $this->be(Administrator::first(), 'admin'); }
    public function finish(): void { DB::disconnect(); }
}

PackageTestHost::assertDisposableEnvironment();
$host = new BaselineBenchmarkHost('benchmark');
$host->start();
$output = [
    'runtime' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'engine' => getenv('DB_CONNECTION'), 'server' => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)],
    'dataset' => ['users' => 1000, 'profiles' => 1000, 'tags' => 1000, 'export_rows' => 10000],
    'measurements' => [],
];
function measure(string $name, callable $operation): void
{
    global $output;
    DB::flushQueryLog(); DB::enableQueryLog(); memory_reset_peak_usage(); $start = hrtime(true);
    try { $result = $operation(); $record = ['result' => $result, 'status' => 'completed']; }
    catch (Throwable $e) { $record = ['status' => 'baseline-defect', 'exception' => get_class($e), 'message' => substr($e->getMessage(), 0, 500)]; }
    $record += ['milliseconds' => round((hrtime(true)-$start)/1e6,3), 'queries' => count(DB::getQueryLog()), 'peak_bytes' => memory_get_peak_usage(true)];
    DB::disableQueryLog(); $output['measurements'][$name] = $record;
}
function responseSummary($response): array
{
    $summary = ['http_status' => $response->getStatusCode(), 'bytes' => strlen($response->getContent())];
    if ($response->getStatusCode() >= 400) {
        throw new RuntimeException('HTTP '.$response->getStatusCode().' from baseline feature route (inspect suite failure classification).');
    }
    return $summary;
}
try {
    DB::table('test_user_tags')->delete(); DB::table('test_user_profiles')->delete(); DB::table('test_tags')->delete(); DB::table('test_users')->delete();
    $stamp = '2026-01-01 00:00:00';
    foreach (array_chunk(range(1,1000),100) as $chunk) {
        $users=$profiles=$tags=$pivots=[];
        foreach ($chunk as $i) {
            $users[]=['id'=>$i,'username'=>sprintf('user-%05d',$i),'email'=>"user-$i@example.test",'mobile'=>'123456','avatar'=>'avatar.jpg','password'=>'synthetic','created_at'=>$stamp,'updated_at'=>$stamp];
            $profiles[]=['id'=>$i,'user_id'=>$i,'first_name'=>'User','last_name'=>(string)$i,'postcode'=>'100','address'=>'Synthetic fixture','latitude'=>'1','longitude'=>'2','color'=>'#ffffff','start_at'=>$stamp,'end_at'=>$stamp,'created_at'=>$stamp,'updated_at'=>$stamp];
            $tags[]=['id'=>$i,'name'=>"tag-$i",'created_at'=>$stamp,'updated_at'=>$stamp];
            $pivots[]=['user_id'=>$i,'tag_id'=>$i,'created_at'=>$stamp,'updated_at'=>$stamp];
        }
        DB::table('test_users')->insert($users); DB::table('test_user_profiles')->insert($profiles); DB::table('test_tags')->insert($tags); DB::table('test_user_tags')->insert($pivots);
    }
    // Explicit IDs in the PostgreSQL fixture require advancing identity sequences before form inserts.
    if (getenv('DB_CONNECTION') === 'pgsql') {
        foreach (['test_users','test_user_profiles','test_tags'] as $table) DB::statement("SELECT setval(pg_get_serial_sequence('$table', 'id'), (SELECT max(id) FROM $table))");
    }
    measure('grid_first_page', fn () => responseSummary($host->call('GET','admin/users')));
    measure('grid_later_page_filter', fn () => responseSummary($host->call('GET','admin/users',['page'=>2,'username'=>'user-','_sort'=>['column'=>'id','type'=>'desc']])));
    measure('grid_relation_substitute', fn () => ['ids'=>User::with(['profile','tags'])->orderBy('id')->paginate(20)->pluck('id')->all(), 'purpose'=>'Data/query substitute retained even when full grid rendering fails; does not certify grid output.']);
    measure('form_create', fn () => responseSummary($host->call('POST','admin/users', ['username'=>'benchmark-created','email'=>'benchmark@example.test','password'=>'synthetic','password_confirmation'=>'synthetic','profile'=>['first_name'=>'Bench','last_name'=>'Mark','postcode'=>'100'],'tags'=>[1]])));
    measure('form_invalid', fn () => responseSummary($host->call('POST','admin/users', ['username'=>'invalid','email'=>''])));
    measure('form_update', fn () => responseSummary($host->call('PUT','admin/users/1', ['username'=>'benchmark-updated','email'=>'updated@example.test','profile'=>['first_name'=>'Updated','last_name'=>'User'],'tags'=>[2]])));
    $temp = tempnam(sys_get_temp_dir(),'benchmark-jpeg-'); $image=imagecreatetruecolor(80,40); imagejpeg($image,$temp);
    measure('image_transform_thumbnail', function () use ($temp) {
        $field = new Encore\Admin\Form\Field\Image('avatar'); $field->rotate(90)->thumbnail('small',20,20);
        $path=$field->prepare(new UploadedFile($temp,'fixture.jpg','image/jpeg',null,true));
        return ['path'=>$path,'dimensions'=>getimagesize(public_path('uploads/'.$path)), 'sha256'=>hash_file('sha256',public_path('uploads/'.$path))];
    });
    if (is_file($temp)) unlink($temp);
    DB::table('test_user_tags')->where('user_id','>',1000)->delete();
    DB::table('test_user_profiles')->where('user_id','>',1000)->delete();
    DB::table('test_users')->where('id','>',1000)->delete();
    foreach (array_chunk(range(1001,10000),250) as $chunk) {
        DB::table('test_users')->insert(array_map(fn ($i)=>['id'=>$i,'username'=>"quoted, \"user\" $i 雪",'email'=>"export-$i@example.test",'password'=>'synthetic','created_at'=>$stamp,'updated_at'=>$stamp],$chunk));
    }
    // Use the real exporter chunk/query and formatting path, but own a file stream rather than its send/exit transport.
    measure('csv_chunk_substitute', function () {
        $grid=new Grid(new User()); $grid->disablePagination(); $grid->column('id'); $grid->column('username'); $grid->column('email');
        $exporter=new CsvExporter($grid); $stream=fopen('php://temp/maxmemory:1048576','w+'); $rows=0;
        $exporter->chunk(function ($collection) use ($stream,&$rows) {
            foreach ($collection as $user) { fputcsv($stream,[$user->id,$user->username,$user->email],',','"','\\'); $rows++; }
        },100);
        $bytes=ftell($stream);rewind($stream);$hash=hash('sha256',stream_get_contents($stream));fclose($stream);
        if ($rows !== 10000) throw new RuntimeException('Expected 10000 synthetic exported rows; got '.$rows);
        return ['rows'=>$rows,'bytes'=>$bytes,'sha256'=>$hash,'purpose'=>'Exporter query/chunk plus explicit baseline CSV escaping; final send/exit/scopes remain issue15 acceptance.'];
    });
    $output['budgets']=['query_count'=>'Candidate <= matching successful baseline query count + 2 (explain necessary security additions).','time'=>'Median of 5 equivalent warm runs <= max(2x successful baseline, baseline + 100ms); initial measurements are characterization, final comparison uses repeated medians.','memory'=>'Candidate peak <= max(1.5x matching successful baseline, baseline + 16MiB); streaming export must remain below 128MiB on 10,000 rows.','scope'=>'Baseline-defect scenarios have no implied successful budget; owning issue records repaired behavior before final candidate comparisons.'];
    $file='/results/benchmark-'.getenv('DB_CONNECTION').'.json'; file_put_contents($file,json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
} finally { $host->finish(); }
