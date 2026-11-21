<?php
try {
    $autoload=is_file('/host/vendor/autoload.php')?'/host/vendor/autoload.php':'/application/vendor/autoload.php';
    require $autoload;
    require_once '/application/tests/Support/PackageTestHost.php';
    require_once '/application/tests/Fixtures/ExportModels.php';
    PackageTestHost::assertDisposableEnvironment();
    function ensure($condition,$message) { if (!$condition) throw new RuntimeException($message); }
    $app=PackageTestHost::create(PackageTestHost::directory());
    $app['db']->connection()->getSchemaBuilder()->dropAllTables();
    Illuminate\Support\Facades\Artisan::call('vendor:publish',['--tag'=>'laravel-admin-migrations']);
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $role=Encore\Admin\Auth\Database\Role::create(['slug'=>'administrator','name'=>'Administrator']);
    $actor=Encore\Admin\Auth\Database\Administrator::create(['username'=>'exporter','name'=>'Exporter','password'=>'unused']);$actor->roles()->attach($role);
    Encore\Admin\Auth\Database\Administrator::create(['username'=>'denied','name'=>'Denied','password'=>'unused']);
    $app['db']->connection()->getSchemaBuilder()->create('export_records',function ($table) {
        $table->increments('id');$table->integer('tenant');$table->text('title')->nullable();$table->integer('rank');$table->softDeletes();
    });
    $special=["comma,quote\"slash\\\"end\\","line1\nline2\r\n台灣🙂",'',null,'0'];
    foreach (array_chunk(range(1,30000),500) as $batch) {
        $data=[]; foreach ($batch as $i) $data[]=['id'=>$i,'tenant'=>7,'title'=>$i<=5?$special[$i-1]:"row $i ".str_repeat('x',256),'rank'=>$i,'deleted_at'=>null];
        $app['db']->table('export_records')->insert($data);
    }
    $app['db']->table('export_records')->insert([['id'=>30001,'tenant'=>8,'title'=>'FOREIGN','rank'=>1,'deleted_at'=>null],['id'=>30002,'tenant'=>7,'title'=>'DELETED','rank'=>1,'deleted_at'=>date('Y-m-d H:i:s')]]);
    $dir=sys_get_temp_dir().'/export-probe-'.bin2hex(random_bytes(6));mkdir($dir);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['EXPORT_AUTOLOAD']=$autoload;$env['EXPORT_PROBE_DIR']=$dir;
    $server=proc_open([PHP_BINARY,'-d','display_errors=0','-d','error_reporting=32767','-S',$address,'/application/scripts/probe-export-router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,'/application',$env);
    ensure(is_resource($server),'Server startup');
    try {
        for ($i=0;$i<500;$i++) { $socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.05);if ($socket) {fclose($socket);break;} usleep(10000); }
        ensure($socket!==false,'Native server readiness '.file_get_contents($dir.'/server.log'));
        $get=function ($case,array $query) use ($address,$dir) {
            $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>60,'header'=>'X-Requested-With: XMLHttpRequest']]);
            $input=fopen('http://'.$address.'/admin/export-fixture?'.http_build_query(['case'=>$case]+$query),'r',false,$context);
            ensure($input!==false,'Open '.$case);$meta=stream_get_meta_data($input);$file=$dir.'/'.$case.'.body';$out=fopen($file,'w');stream_copy_to_stream($input,$out);fclose($input);fclose($out);
            return [$file,$meta['wrapper_data']];
        };
        $parse=function ($file) { $h=fopen($file,'r');ensure(fread($h,3)==="\xEF\xBB\xBF",'BOM '.substr(file_get_contents($file),0,1000));$all=[];while (($row=fgetcsv($h,null,',','"',''))!==false) $all[]=$row;fclose($h);return $all; };
        $results=[];
        $cases=[
            'all'=>[['_export_'=>'all','rank'=>['start'=>3,'end'=>203],'_sort'=>['column'=>'rank','type'=>'desc']],range(203,3)],
            'selected'=>[['_export_'=>'selected:1,3,200,30001,30002','rank'=>['start'=>3,'end'=>203],'_sort'=>['column'=>'rank','type'=>'desc']],[200,3]],
            'page'=>[['_export_'=>'page:2','page'=>1,'per_page'=>10,'rank'=>['start'=>3,'end'=>203],'_sort'=>['column'=>'rank','type'=>'desc']],range(193,184)],
            'empty'=>[['_export_'=>'all','rank'=>['start'=>40000]],[]],
        ];
        foreach ($cases as $case=>[$query,$expected]) {
            [$file,$headers]=$get($case,$query);$data=$parse($file);
            ensure(str_contains(implode('\n',$headers),'text/csv;charset=UTF-8'),'CSV content type');
            ensure(str_contains(implode('\n',$headers),'attachment;filename="fixture.csv"'),'CSV disposition');
            ensure(array_shift($data)===['ID','Title','Rank'],'CSV headings '.$case);
            ensure(array_map('intval',array_column($data,0))===$expected,'CSV scoped IDs '.$case);
            foreach ($data as $offset=>$row) { $id=$expected[$offset];$raw=$id<=5?($special[$id-1]??''):"row $id ".str_repeat('x',256);ensure($row===[(string)$id,$raw,(string)$id],'Original CSV values '.$case); }
            $results[$case]=['rows'=>count($data),'headers'=>$headers,'metrics'=>json_decode(file_get_contents($dir.'/'.$case.'.json'),true)];
        }
        ensure($results['all']['metrics']['chunks']===[100,100,1],'Actual chunk boundaries');
        [$file,$headers]=$get('fidelity',['_export_'=>'selected:1,2,3,4,5']);$data=$parse($file);
        ensure(array_shift($data)===['ID','Title,標題'],'Visibility/only/except/title callback');
        foreach ($data as $i=>$row) {
            $raw=$special[$i]??'';$encoded=$raw;
            ensure($row===[(string)($i+1),$raw.'|display:'.$encoded],'Fidelity/callback '.$i.' '.json_encode($row));
        }
        $results['fidelity']=['rows'=>count($data),'roundtrip'=>true];
        [$file,$headers]=$get('visible',['_export_'=>'selected:1,2','_columns_'=>'id,rank']);$data=$parse($file);
        ensure($data===[['ID','Rank'],['1','1'],['2','2']],'Explicit visible columns');
        $results['visible']=['rows'=>2,'columns'=>['id','rank']];
        [$file,$headers]=$get('allhidden',['_export_'=>'selected:1,2']);ensure($parse($file)===[[null],[null],[null]],'All-hidden columns leaked values');
        $results['allhidden']=['rows'=>2,'columns'=>[]];
        foreach (['denied','disabled','invalid'] as $case) {
            [$file,$headers]=$get($case,['_export_'=>$case==='invalid'?'bogus':'all']);
            ensure(str_contains($headers[0],$case==='invalid'?'422':'403'),'Denied/invalid status '.$case.' '.implode(',',$headers).' '.file_get_contents($file));
            ensure(!str_starts_with(file_get_contents($file),"\xEF\xBB\xBF"),'Unauthorized CSV '.$case);
            $results[$case]=['status'=>$headers[0]];
        }
        $peaks=[];
        foreach ([1000,30000] as $count) {
            $case='memory'.$count;[$file,$headers]=$get($case,['_export_'=>'all','rank'=>['end'=>$count]]);
            $h=fopen($file,'r');ensure(fread($h,3)==="\xEF\xBB\xBF",'Memory BOM');fgetcsv($h,null,',','"','');$actual=0;
            while (($row=fgetcsv($h,null,',','"',''))!==false) { ++$actual; ensure((int)$row[0]===$actual,'Memory IDs'); }fclose($h);
            ensure($actual===$count,'Complete measured stream rowcount');$m=json_decode(file_get_contents($dir.'/'.$case.'.json'),true);ensure($m['rows']===$count,'Callback rowcount');
            $peaks[]=$m['peak_bytes'];$results[$case]=['rows'=>$actual,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'metrics'=>$m];
        }
        ensure(max($peaks)-min($peaks)<=8*1024*1024 && max($peaks)<=96*1024*1024,'Bounded stream memory');
        [$file,$headers]=$get('failure',['_export_'=>'all']);$data=$parse($file);$m=json_decode(file_get_contents($dir.'/failure.json'),true);
        $partial=array_values(array_filter(array_slice($data,1),fn ($row)=>count($row)===3 && ctype_digit($row[0]??'')));
        ensure(count($partial)===100&&$m['rows']===101,'Interrupted stream must remain partial '.json_encode(['parsed'=>count($data),'valid'=>count($partial),'metrics'=>$m]));
        ensure(str_contains(file_get_contents($dir.'/server.log'),'Injected export failure'),'Actual stream exception surfaced');
        $results['failure']=['parsed_rows'=>count($partial),'expected_rows'=>30000,'complete'=>false];
        if (class_exists(Maatwebsite\Excel\Excel::class)) {
            foreach ($cases+['fidelity'=>[['_export_'=>'selected:1,2,3,4,5'],[1,2,3,4,5]]] as $case=>[$query,$expected]) {
                [$file,$headers]=$get('excel'.$case,$query);ensure(str_contains(implode('\n',$headers),'spreadsheetml.sheet'),'Excel content type '.implode(',',$headers));
                $book=PhpOffice\PhpSpreadsheet\IOFactory::load($file);$sheet=$book->getActiveSheet();$values=$sheet->toArray(null,false,false,false);
                ensure(array_shift($values)===['ID','Title','Rank'],'Workbook headings '.$case);
                if ($expected===[]) $values=array_values(array_filter($values,fn ($row)=>$row!==[null,null,null]));
                ensure(array_map('intval',array_column($values,0))===$expected,'Workbook scoped IDs '.$case);
                foreach ($values as $offset=>$row) {
                    $id=$expected[$offset];$title=$id<=5?$special[$id-1]:"row $id ".str_repeat('x',256);
                    $excelTitle=$title===''?null:($title==='0'?0:($title===null?null:str_replace("\r\n","\n",$title)));
                    ensure($row===[$id,$excelTitle,$id],'Workbook values/types '.$case.' '.json_encode($row));
                    ensure($sheet->getCell('A'.($offset+2))->getDataType()==='n'&&$sheet->getCell('C'.($offset+2))->getDataType()==='n','Workbook numeric type');
                    if ($title!==null && $title!=='') ensure($sheet->getCell('B'.($offset+2))->getDataType()===($title==='0'?'n':'s'),'Workbook title type');
                }
                $artifact='/results/exports-workbook-'.$case.'-'.Illuminate\Foundation\Application::VERSION.'-'.getenv('DB_CONNECTION').'.xlsx';
                ensure(copy($file,$artifact),'Retain real workbook evidence');
                $results['excel'.$case]=['rows'=>count($values),'headers'=>$headers,'sha256'=>hash_file('sha256',$file),'artifact'=>$artifact];$book->disconnectWorksheets();
            }
            $results['excel_version']=Composer\InstalledVersions::getPrettyVersion('maatwebsite/excel');
            $results['phpspreadsheet_version']=Composer\InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet');
        }
        if (!class_exists(Maatwebsite\Excel\Excel::class)) {
            try { class_exists(Encore\Admin\Grid\Exporters\ExcelExporter::class);throw new RuntimeException('Absent optional Excel dependency accepted'); }
            catch (LogicException $error) { ensure(str_contains($error->getMessage(),'maatwebsite/excel ^4.0'),'Controlled missing integration guidance'); }
            $results['excel_absent']=['controlled_error'=>true,'workbooks_executed'=>false];
        }
        $log=file_get_contents($dir.'/server.log');ensure(!preg_match('/Deprecated|Warning:|PHP Warning/',$log),'Unexpected PHP diagnostics '.$log);
        echo json_encode(['php'=>PHP_VERSION,'laravel'=>Illuminate\Foundation\Application::VERSION,'database'=>getenv('DB_CONNECTION'),'autoload'=>$autoload,'cases'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    } catch (Throwable $error) {
        copy($dir.'/server.log','/results/exports-server-failure-'.Illuminate\Foundation\Application::VERSION.'-'.getenv('DB_CONNECTION').'-'.(class_exists(Maatwebsite\Excel\Excel::class)?'excel':'core').'.log');
        throw $error;
    } finally { proc_terminate($server);proc_close($server); (new Illuminate\Filesystem\Filesystem())->deleteDirectory($dir); }
} catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1); }
