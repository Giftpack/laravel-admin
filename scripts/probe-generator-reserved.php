<?php
/** Reproduce the actual cycle1 reserved-type compiler defect, or verify its correction. */
try {
    require '/host/vendor/autoload.php';require '/application/tests/Support/PackageTestHost.php';PackageTestHost::assertDisposableEnvironment();
    $app=PackageTestHost::create(PackageTestHost::directory());
    config(['admin.extension_dir'=>storage_path('extensions')]);$fixed=($argv[1]??'')==='fixed';$results=[];
    if(!$fixed){
        // Replay the reviewed immutable commands, rather than silently reproducing against the fix.
        $captured='/application/.scratch/php85-laravel13-upgrade/issue18-cycle1-candidate/src/Console';
        $frozen=json_decode(file_get_contents(dirname($captured,2).'/provenance.json'),true);
        foreach($frozen['files']as$path=>$hash)if(str_starts_with($path,'src/Console/stubs/')&&hash_file('sha256','/application/'.$path)!==$hash)throw new RuntimeException('Reviewed template changed: '.$path);
        foreach(['ValidatesGeneratedClass','FormCommand','ActionCommand','ExtendCommand']as$class){
            $source=str_replace('namespace Encore\\Admin\\Console;','namespace Reviewed18;',file_get_contents($captured.'/'.$class.'.php'));
            $source=str_replace('__DIR__',var_export('/application/src/Console',true),$source);
            eval(substr($source,5));
        }
        foreach(['FormCommand','ActionCommand','ExtendCommand']as$class)$app[Illuminate\Contracts\Console\Kernel::class]->registerCommand($app->make('Reviewed18\\'.$class));
    }
    foreach (['String','Int','Self','sTrInG'] as $name) {
        foreach (['admin:form','admin:action','admin:extend'] as $command) {
            $package='vendor/'.strtolower($name).'-'.count($results);
            $args=$command==='admin:extend'?['extension'=>$package,'--namespace'=>'Vendor\\'.$name]:['name'=>$name,'--namespace'=>'App\\Generated'];
            $path=$command==='admin:extend'?storage_path('extensions/'.$package.'/src/'.$name.'.php'):app_path('Generated/'.$name.'.php');
            $code=Illuminate\Support\Facades\Artisan::call($command,$args);$exists=file_exists($path);
            if($fixed){if($code!==1||$exists)throw new RuntimeException('Expected controlled nonzero/no file: '.$command.' '.$name);$results[]=['command'=>$command,'name'=>$name,'exit'=>$code,'destination_exists'=>false];continue;}
            if($code===1&&!$exists){$results[]=['command'=>$command,'name'=>$name,'already_rejected_by_parser'=>true];continue;}
            if($code!==0||!$exists)throw new RuntimeException('Cycle1 reproduction generation failed: '.$command.' '.$name);
            $process=new Symfony\Component\Process\Process([PHP_BINARY,'-l',$path]);$process->setTimeout(10);$process->run();
            if($process->isSuccessful())throw new RuntimeException('Expected compiler rejection: '.$command.' '.$name);
            $results[]=['command'=>$command,'name'=>$name,'generation_exit'=>$code,'destination_exists'=>true,'compile_exit'=>$process->getExitCode(),'diagnostic'=>$process->getErrorOutput().$process->getOutput()];
            // Form and action share the destination; remove only the generated reproduction output.
            if($command!=='admin:extend')unlink($path);
        }
    }
    echo json_encode(['status'=>$fixed?'passed':'reproduced','php'=>PHP_VERSION,'laravel'=>$app->version(),'checks'=>$results],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable$e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
