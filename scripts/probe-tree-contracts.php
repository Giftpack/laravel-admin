<?php
/** Supplemental boundary proof; original traversal runs in bounded disposable subprocesses. */
if (($argv[1] ?? '') === 'worker') {
    $source='/application/.scratch/php85-laravel13-upgrade/issue16-before/src/Traits/ModelTree.php';
    eval(substr(str_replace('namespace Encore\\Admin\\Traits;', 'namespace Issue16ZeroFrozen;', file_get_contents($source)),5));
    class ZeroProbeBase {}
    class BoundedOriginalZeroTree extends ZeroProbeBase {
        use Issue16ZeroFrozen\ModelTree { buildNestedArray as frozenBuildNestedArray; }
        public $visits=0;
        public $id;
        public function getKeyName() { return 'id'; }
        public function allNodes() { return [['id'=>$this->id,'parent_id'=>0,'order'=>1,'title'=>'Zero']]; }
        protected function buildNestedArray(array $nodes=[], $parentId=0) {
            if (++$this->visits>16) throw new RuntimeException('bounded repeated traversal');
            return $this->frozenBuildNestedArray($nodes,$parentId);
        }
    }
    $model=new BoundedOriginalZeroTree(); $model->id=$argv[2]==='integer'?0:'0';
    try { $model->toTree(); throw new RuntimeException('Expected repeat not observed'); }
    catch (RuntimeException $e) {
        if ($e->getMessage()!=='bounded repeated traversal') throw $e;
        echo json_encode(['key_type'=>gettype($model->id),'key'=>$model->id,'visits'=>$model->visits,'result'=>'guarded original traversal repeats','frozen_source_sha256'=>hash_file('sha256',$source)]).PHP_EOL;
        exit(0);
    }
}
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    $app=PackageTestHost::create(PackageTestHost::directory());
    require_once '/application/tests/Fixtures/TreeModels.php';
    $schema=$app['db']->connection()->getSchemaBuilder(); $schema->dropAllTables();
    $schema->create('tree_records',function($t){$t->string('code')->primary();$t->string('ancestor')->default('0');$t->string('label');$t->integer('position')->default(0);$t->softDeletes();});
    TreeRecord::insert([['code'=>'uuid-root','ancestor'=>'0','label'=>'Root','position'=>1],['code'=>'uuid-child','ancestor'=>'uuid-root','label'=>'Child','position'=>2]]);
    $tree=new Encore\Admin\Tree(new TreeRecord());
    $tree->branch(fn($branch)=>'<em class="trusted">'.$branch['code'].' '.$branch['label'].'</em>');
    $html=$tree->render();
    if (!str_contains($html,'<em class="trusted">uuid-child Child</em>')) throw new RuntimeException('Trusted custom branch callback changed');
    $options=TreeRecord::selectOptions(null,'Custom root');
    if ($options[0]!=='Custom root'||!str_ends_with($options['uuid-child'],'Child')) throw new RuntimeException('Custom options');
    $tree->saveOrder('[{"id":"uuid-child","children":[{"id":"uuid-root"}]}]');
    if (TreeRecord::findOrFail('uuid-root')->ancestor!=='uuid-child') throw new RuntimeException('Arbitrary string-key reorder');
    $before=TreeRecord::orderBy('code')->get()->toArray(); $rejections=[];
    foreach ([0,'0'] as $zero) {
        try { $tree->saveOrder(json_encode([['id'=>$zero]])); throw new RuntimeException('Zero node accepted'); }
        catch (InvalidArgumentException $e) { $rejections[]=['key_type'=>gettype($zero),'key'=>$zero,'error'=>$e->getMessage()]; }
        if (TreeRecord::orderBy('code')->get()->toArray()!==$before) throw new RuntimeException('Zero input changed hierarchy');
    }
    $original=[];
    foreach (['integer','string'] as $kind) {
        $process=proc_open(['timeout','5','php','-d','memory_limit=64M',__FILE__,'worker',$kind],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        if ($code!==0) throw new RuntimeException('Bounded subprocess failed '.$code.' '.$error);
        $original[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'original_zero_traversal'=>$original,'final_zero_rejections'=>$rejections,'checks'=>['actual_custom_string_render_options_reorder','trusted_custom_branch_html_preserved','int_and_string_zero_consistently_rejected_without_writes','original_traversal_guard17_timeout5s_memory64M','root_parent_sentinel0_reserved']],JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,(string)$e.PHP_EOL);exit(1); }
