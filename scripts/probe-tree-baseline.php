<?php
/** Reproduce original behavior from the actual pre16 capture, isolated by namespace only. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    $app=PackageTestHost::create(PackageTestHost::directory());
    $source='/application/.scratch/php85-laravel13-upgrade/issue16-before/src/Traits/ModelTree.php';
    eval(substr(str_replace('namespace Encore\\Admin\\Traits;', 'namespace Issue16Frozen;', file_get_contents($source)),5));
    class FrozenTreeRecord extends Illuminate\Database\Eloquent\Model {
        use Issue16Frozen\ModelTree { boot as treeBoot; }
        protected $table='tree_baseline'; public $timestamps=false; protected $guarded=[];
        public static $boots=0;
        protected static function boot() { ++static::$boots; static::treeBoot(); }
    }
    $schema=$app['db']->connection()->getSchemaBuilder();
    $schema->dropAllTables();
    $schema->create('tree_baseline',function($t){$t->increments('id');$t->integer('parent_id')->default(0);$t->integer('order')->default(0);$t->string('title');});
    FrozenTreeRecord::insert([['id'=>1,'parent_id'=>0,'order'=>9,'title'=>'One'],['id'=>2,'parent_id'=>0,'order'=>8,'title'=>'Two']]);
    $seen=0; FrozenTreeRecord::saving(function()use(&$seen){++$seen;});
    $row=FrozenTreeRecord::findOrFail(1); $row->title='Saved'; $row->save();
    if ($seen!==0||FrozenTreeRecord::$boots!==1) throw new RuntimeException('Original hook characterization changed');
    $error=null;
    try { FrozenTreeRecord::saveOrder([['id'=>1],['id'=>999]]); }
    catch(Throwable $e) { $error=$e->getMessage(); }
    if (!$error || FrozenTreeRecord::findOrFail(1)->order!==1) throw new RuntimeException('Original partial write did not reproduce');
    config(['database.connections.selected'=>config('database.connections.'.config('database.default'))]);
    $queries=[]; Illuminate\Support\Facades\DB::listen(function($e)use(&$queries){$queries[]=$e->connectionName;});
    (new FrozenTreeRecord())->setConnection('selected')->allNodes();
    if ($queries!==[config('database.default')]) throw new RuntimeException('Original connection leak did not reproduce');
    echo json_encode(['status'=>'characterized','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'frozen_source_sha256'=>hash_file('sha256',$source),'namespace_only_isolation'=>true,'boot_count'=>FrozenTreeRecord::$boots,'later_saving_listener_calls'=>$seen,'missing_reference_error'=>$error,'first_branch_order_after_failure'=>1,'selected_connection_query_names'=>$queries],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,(string)$e.PHP_EOL);exit(1); }
