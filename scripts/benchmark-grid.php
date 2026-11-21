<?php
require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/tests/TestCase.php';
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Support\Facades\DB;
use Tests\Models\User;
final class GridBenchmarkHost extends TestCase
{
    public function benchmark(): void {}
    public function start(): void { parent::setUp(); $this->be(Administrator::first(), 'admin'); }
}
PackageTestHost::assertDisposableEnvironment();
$mode=$argv[1]??'candidate';
$host=new GridBenchmarkHost('benchmark');$host->start();
if (!str_starts_with(app()->version(),$mode==='baseline'?'12.':'13.')) throw new RuntimeException('Unexpected framework source');
$stamp='2026-01-01 00:00:00';
foreach(['test_user_tags','test_user_profiles','test_tags','test_users']as$table)DB::table($table)->delete();
foreach(array_chunk(range(1,1000),100)as$chunk){$users=$profiles=$tags=$pivots=[];foreach($chunk as$i){
    $users[]=['id'=>$i,'username'=>sprintf('user-%05d',$i),'email'=>"user-$i@example.test",'mobile'=>'123456','avatar'=>'avatar.jpg','password'=>'synthetic','created_at'=>$stamp,'updated_at'=>$stamp];
    $profiles[]=['id'=>$i,'user_id'=>$i,'first_name'=>'User','last_name'=>(string)$i,'postcode'=>'100','address'=>'Synthetic fixture','latitude'=>'1','longitude'=>'2','color'=>'#ffffff','start_at'=>$stamp,'end_at'=>$stamp,'created_at'=>$stamp,'updated_at'=>$stamp];
    $tags[]=['id'=>$i,'name'=>"tag-$i",'created_at'=>$stamp,'updated_at'=>$stamp];$pivots[]=['user_id'=>$i,'tag_id'=>$i,'created_at'=>$stamp,'updated_at'=>$stamp];
}DB::table('test_users')->insert($users);DB::table('test_user_profiles')->insert($profiles);DB::table('test_tags')->insert($tags);DB::table('test_user_tags')->insert($pivots);}
$operator=Administrator::create(['username'=>'benchmark-operator','name'=>'Benchmark operator','password'=>'synthetic']);
$permission=Encore\Admin\Auth\Database\Permission::create(['name'=>'Grid browse','slug'=>'grid-browse','http_method'=>['GET'],'http_path'=>'/users*']);
$operator->permissions()->attach($permission);
$operations=[
    'grid_first_page'=>function()use($host){$res=$host->call('GET','admin/users');if($res->getStatusCode()!==200)throw new RuntimeException('Grid status');$crawler=new Symfony\Component\DomCrawler\Crawler($res->getContent());if($crawler->filter('td.column-id')->count()!==20)throw new RuntimeException('Grid rows');return ['status'=>200,'rows'=>20];},
    'grid_later_page_filter'=>function()use($host){$res=$host->call('GET','admin/users',['page'=>2,'username'=>'user-','_sort'=>['column'=>'id','type'=>'desc']]);if($res->getStatusCode()!==200)throw new RuntimeException('Grid status');$crawler=new Symfony\Component\DomCrawler\Crawler($res->getContent());$ids=$crawler->filter('td.column-id')->each(fn($node)=>(int)trim($node->text()));if($ids!==range(980,961))throw new RuntimeException('Grid sort/rows');return ['status'=>200,'ids'=>$ids];},
    'grid_restricted_first_page'=>function()use($host,$operator){$host->be($operator,'admin');$res=$host->call('GET','admin/users');if($res->getStatusCode()!==200)throw new RuntimeException('Restricted grid status');$crawler=new Symfony\Component\DomCrawler\Crawler($res->getContent());if($crawler->filter('td.column-id')->count()!==20)throw new RuntimeException('Restricted grid rows');return ['status'=>200,'rows'=>20];},
    'grid_restricted_100_rows'=>function()use($host,$operator){$host->be($operator,'admin');$res=$host->call('GET','admin/users',['per_page'=>100]);if($res->getStatusCode()!==200)throw new RuntimeException('Restricted grid status');$crawler=new Symfony\Component\DomCrawler\Crawler($res->getContent());if($crawler->filter('td.column-id')->count()!==100)throw new RuntimeException('Restricted grid rows');return ['status'=>200,'rows'=>100];},
    'grid_relation_substitute'=>fn()=>['ids'=>User::with(['profile','tags'])->orderBy('id')->paginate(20)->pluck('id')->all()],
];
$output=['mode'=>$mode,'php'=>PHP_VERSION,'framework'=>app()->version(),'engine'=>getenv('DB_CONNECTION'),'dataset'=>['users'=>1000,'profiles'=>1000,'tags'=>1000],'source'=>[],'measurements'=>[]];
foreach([Encore\Admin\Grid::class,Encore\Admin\Grid\Model::class,Illuminate\Foundation\Application::class]as$class){$file=(new ReflectionClass($class))->getFileName();$output['source'][$class]=['path'=>$file,'sha256'=>hash_file('sha256',$file)];}
foreach($operations as$name=>$operation){$operation();$runs=[];for($i=0;$i<5;$i++){
    DB::enableQueryLog();DB::flushQueryLog();memory_reset_peak_usage();$start=hrtime(true);$result=$operation();$runs[]=['milliseconds'=>round((hrtime(true)-$start)/1e6,3),'queries'=>count(DB::getQueryLog()),'peak_bytes'=>memory_get_peak_usage(true),'result'=>$result];if ($i === 0) { $runs[$i]['sql_without_bindings'] = array_column(DB::getQueryLog(), 'query'); } DB::disableQueryLog();
}$times=array_column($runs,'milliseconds');sort($times);$output['measurements'][$name]=['median_ms'=>$times[2],'runs'=>$runs];}
$file='/results/issue10-benchmark-'.$mode.'-'.getenv('DB_CONNECTION').'.json';file_put_contents($file,json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
