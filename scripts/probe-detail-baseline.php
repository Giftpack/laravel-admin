<?php
require '/host/vendor/autoload.php';
require_once '/application/tests/Support/PackageTestHost.php';
PackageTestHost::assertDisposableEnvironment();
$app=PackageTestHost::create(PackageTestHost::directory());
$app['view']->addNamespace('frozen17','/application/.scratch/php85-laravel13-upgrade/issue17-before/resources/views');
// Execute the actual captured pre17 sources under isolated namespaces.
foreach (['Middleware/Pjax','Exception/Handler'] as $file) {
 $source='/application/.scratch/php85-laravel13-upgrade/issue17-before/src/'.$file.'.php';
 eval(substr(str_replace(['namespace Encore\\Admin\\',"'admin::partials.exception'"], ['namespace Issue17Frozen\\',"'frozen17::partials.exception'"],file_get_contents($source)),5));
 $hashes[$file]=hash_file('sha256',$source);
}
class FrozenPjaxProbe extends Issue17Frozen\Middleware\Pjax { public function failed($r){ return $this->handleErrorResponse($r); } }
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
try {(new FrozenPjaxProbe)->failed(new Illuminate\Http\Response('Unavailable',503)); $failed='unexpected success';} catch(Throwable $e){$failed=get_class($e).': '.$e->getMessage();}
try {Issue17Frozen\Exception\Handler::renderException(new TypeError('fixture-secret')); $throwable='unexpected success';} catch(Throwable $e){$throwable=get_class($e).': '.$e->getMessage();}
restore_error_handler();
config(['app.debug'=>false]);
$feedback=Issue17Frozen\Exception\Handler::renderException(new RuntimeException('fixture-secret'));
echo json_encode(['php'=>PHP_VERSION,'laravel'=>$app->version(),'engine'=>config('database.default'),'source_hashes'=>$hashes,'failed_response'=>$failed,'throwable'=>$throwable,'debug_false_blank'=>trim($feedback)===''],JSON_PRETTY_PRINT).PHP_EOL;
if (!str_contains($failed,'get_class')||!str_contains($throwable,'TypeError')||trim($feedback)!=='') exit(1);

foreach (['steps','navbar','field','assets','attributes','escaping','grid'] as $mode) {
 $process=proc_open(['php','/application/scripts/probe-detail-original.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 echo $out;if($code!==0){fwrite(STDERR,$error);exit($code);}
}
